<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Memindai storage/logs/laravel*.log dan menyimpan entri ERROR ke atas ke
 * nastari_log_entries.
 *
 * Kenapa ini ada. Event terstruktur di nastari_events hanya memuat apa yang
 * berhasil dicatat aplikasi. Tiga hal tidak pernah sampai ke sana:
 *
 *   1. fatal error PHP — prosesnya mati sebelum shutdown handler menulis apa
 *      pun (inilah sebabnya kasus memori habis di generate surat dulu tidak
 *      meninggalkan jejak apa pun kecuali di file log);
 *   2. exception di panel admin, yang tidak lewat jalur WhatsApp sama sekali;
 *   3. setiap Log::error yang tidak punya padanan event terstruktur.
 *
 * Ketiganya hanya ada di file log, dan channel `daily` menghapus file itu
 * setelah 14 hari. Tabelnya menyimpan lebih lama.
 *
 * Tiga aturan yang tidak boleh dilanggar kelas ini:
 *
 *   1. **Tidak boleh menjadi beban.** Posisi baca terakhir setiap file
 *      disimpan, jadi tiap pemindaian hanya membaca byte baru. File dibaca
 *      mengalir baris per baris — file_get_contents pada log 200 MB adalah
 *      pengulangan persis kesalahan yang sudah kita perbaiki di kop surat.
 *   2. **Tidak boleh menyimpan kredensial.** URL Darwinbox membawa api_key di
 *      query string dan header Authorization ikut tercetak di beberapa jejak,
 *      jadi setiap pesan dilewatkan penyaring sebelum disimpan. Nomor telepon
 *      dan alamat email juga disamarkan.
 *   3. **Tidak boleh mencampur metrik.** Barisnya tidak masuk nastari_events,
 *      karena sebagian error sudah punya baris terstrukturnya sendiri dan
 *      penggabungan akan menghitung kejadian yang sama dua kali.
 */
class NastariLogScanner
{
    /** [2026-09-10 10:52:58] local.ERROR: pesan… (mikrodetik opsional) */
    private const TS = '/^\[(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})[^\]]*\]\s*([A-Za-z0-9_-]+)\.([A-Z]+):\s*(.*)$/';

    /** Baris log tidak dibaca lebih panjang dari ini sebelum dipotong. */
    private const MAX_LINE = 8192;

    private const INSERT_CHUNK = 200;

    /**
     * @return array{files:int, scanned:int, bytes:int, entries:int, inserted:int, skipped:int, pruned:int, rotated:int}
     */
    public function scan(bool $fresh = false): array
    {
        if ($fresh) {
            // Data turunan sepenuhnya: aman dibangun ulang, karena file lognya
            // yang menjadi sumber. Yang belum dirotasi akan terbaca kembali.
            DB::table('nastari_log_entries')->truncate();
            DB::table('nastari_log_scans')->truncate();
        }

        $config = (array) config('nastari.logging.files', []);
        $levels = array_map('strtoupper', (array) ($config['levels'] ?? ['ERROR']));
        $maxMessage = (int) ($config['max_message'] ?? 400);
        $maxEntries = (int) ($config['max_entries_per_scan'] ?? 5000);

        $result = [
            'files' => 0, 'scanned' => 0, 'bytes' => 0, 'entries' => 0,
            'inserted' => 0, 'skipped' => 0, 'pruned' => 0, 'rotated' => 0,
        ];

        $state = DB::table('nastari_log_scans')->get()->keyBy('source_file');
        $batch = [];
        $budget = $maxEntries;

        foreach ($this->files($config) as $path) {
            $result['files']++;

            $name = basename($path);
            $size = @filesize($path);

            if ($size === false) {
                continue;
            }

            $row = $state->get($name);
            $offset = (int) ($row->last_offset ?? 0);

            // File lebih kecil daripada posisi terakhir berarti dirotasi atau
            // dipotong: mulai lagi dari awal, jangan melompati isi barunya.
            if ($size < $offset) {
                $offset = 0;
                $result['rotated']++;
            }

            if ($size === $offset || $budget <= 0) {
                continue;
            }

            $result['scanned']++;

            [$entries, $newOffset] = $this->readFrom($path, $offset, $levels, $maxMessage, $budget);

            $result['bytes'] += max(0, $newOffset - $offset);
            $result['entries'] += count($entries);
            $budget -= count($entries);

            foreach ($entries as $entry) {
                $entry['source_file'] = $name;
                $batch[$entry['fingerprint']] = $entry;

                if (count($batch) >= self::INSERT_CHUNK) {
                    [$in, $skip] = $this->flush($batch);
                    $result['inserted'] += $in;
                    $result['skipped'] += $skip;
                    $batch = [];
                }
            }

            $this->rememberOffset($name, $newOffset, (int) $size, count($entries), $row !== null);
        }

        if ($batch !== []) {
            [$in, $skip] = $this->flush($batch);
            $result['inserted'] += $in;
            $result['skipped'] += $skip;
        }

        $result['pruned'] = $this->prune((int) ($config['retention_days'] ?? 30));

        return $result;
    }

    /** @return array<int, string> */
    private function files(array $config): array
    {
        $dir = (string) ($config['path'] ?? storage_path('logs'));
        $pattern = (string) ($config['pattern'] ?? 'laravel*.log');

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $pattern) ?: [];
        sort($files);

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * Membaca satu file dari $offset sampai akhir dan mengembalikan entri yang
     * levelnya diminta, beserta posisi baca yang baru.
     *
     * Baris lanjutan (stack trace) bukan entri tersendiri: ia menempel pada
     * entri di atasnya, dan hanya diambil sampai batas panjang pesan — sisanya
     * dilewati tanpa disimpan di memori.
     *
     * @param  array<int, string>  $levels
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function readFrom(string $path, int $offset, array $levels, int $maxMessage, int $budget): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [[], $offset];
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $entries = [];
        $pending = null;

        while (($line = fgets($handle, self::MAX_LINE)) !== false) {
            $line = rtrim($line, "\r\n");

            if (preg_match(self::TS, $line, $m) === 1) {
                if ($pending !== null) {
                    $entries[] = $this->finalise($pending, $maxMessage);
                    $pending = null;

                    if (count($entries) >= $budget) {
                        break;
                    }
                }

                $level = strtoupper($m[4]);

                if (! in_array($level, $levels, true)) {
                    continue;
                }

                $pending = [
                    'date'    => $m[1],
                    'time'    => $m[2],
                    'channel' => Str::limit($m[3], 24, ''),
                    'level'   => $level,
                    'raw'     => $m[5],
                ];

                continue;
            }

            // Baris lanjutan. Hanya berguna selama pesannya belum penuh.
            if ($pending !== null && strlen($pending['raw']) < $maxMessage * 3) {
                $pending['raw'] .= ' ' . trim($line);
            }
        }

        if ($pending !== null && count($entries) < $budget) {
            $entries[] = $this->finalise($pending, $maxMessage);
        }

        $newOffset = ftell($handle);
        fclose($handle);

        return [$entries, $newOffset === false ? $offset : $newOffset];
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>
     */
    private function finalise(array $pending, int $maxMessage): array
    {
        $raw = (string) $pending['raw'];

        [$exception, $file, $line] = $this->locate($raw);

        $message = $this->redact($this->tidy($raw));
        $message = Str::limit($message, $maxMessage, '…');

        $title = Str::limit($this->normalise($message), 190, '');
        $occurred = $pending['date'] . ' ' . $pending['time'];

        return [
            'logged_at'   => $occurred,
            'entry_date'  => $pending['date'],
            'entry_hour'  => (int) substr($pending['time'], 0, 2),
            'level'       => $pending['level'],
            'channel'     => $pending['channel'],
            'kind'        => $this->classify($message),
            'signature'   => sha1($this->normalise($message)),
            'title'       => $title === '' ? '(tanpa pesan)' : $title,
            'message'     => $message,
            'exception'   => $exception,
            'file'        => $file,
            'line'        => $line,
            'fingerprint' => sha1($occurred . '|' . $pending['level'] . '|' . substr($message, 0, 200)),
            'created_at'  => now(),
        ];
    }

    /**
     * Membuang bagian yang hanya menambah panjang tanpa menambah informasi.
     */
    private function tidy(string $message): string
    {
        // Jejak objek exception Monolog: kelas dan lokasinya sudah diambil
        // terpisah oleh locate(), jadi blok mentahnya tidak perlu disimpan.
        $message = preg_replace('/\s*\{"exception":"\[object\].*$/s', '', $message) ?? $message;
        $message = preg_replace('/\(see https?:\/\/\S+\)/', '', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        $message = $this->shortenPathsIn($message);

        return trim($message);
    }

    /**
     * Memendekkan path absolut yang muncul di dalam badan pesan.
     *
     * Kolom `file` sudah dinormalkan oleh shortenPath(), tapi jejak yang sama
     * biasanya juga tercetak di teks pesannya. Membersihkan salah satu saja
     * berarti struktur direktori server tetap tampil di dashboard.
     */
    private function shortenPathsIn(string $text): string
    {
        return preg_replace(
            '#(?:[A-Za-z]:)?[\\\\/][^\s"\']*?[\\\\/](vendor|app|bootstrap|config|database|public|resources|routes|storage|tests)[\\\\/]#',
            '$1/',
            $text
        ) ?? $text;
    }

    /**
     * Menyaring apa pun yang tidak boleh ikut tersimpan.
     *
     * Ini bukan kehati-hatian berlebihan: URL Darwinbox membawa api_key di
     * query string, dan pesan error cURL mencetak URL utuh. Penyaring ini
     * sengaja berlebihan — menyensor terlalu banyak tidak merugikan, sedangkan
     * satu kredensial yang lolos akan tersimpan di database dan tampil di
     * dashboard.
     */
    private function redact(string $message): string
    {
        $rules = [
            // key=value dan "key": "value" untuk nama-nama yang sensitif.
            '/\b(api[_-]?key|apikey|dataset[_-]?key|auth[_-]?token|access[_-]?token|token|password|passwd|pwd|secret|credential|signature|authorization|cookie)\b(\s*["\']?\s*[:=]\s*["\']?)([^\s"\'&,;}\]]{3,})/i'
                => '$1$2[disensor]',

            // Header bertipe skema.
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._\-\/+=]{8,}/i' => '$1 [disensor]',

            // Sisa string panjang tanpa spasi yang berbentuk kunci.
            '/\b[A-Za-z0-9]{32,}\b/' => '[disensor]',
        ];

        foreach ($rules as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message) ?? $message;
        }

        // Nomor telepon dan email: data pribadi yang tidak dibutuhkan untuk
        // memahami sebuah error.
        $message = preg_replace_callback(
            '/\b(62\d{2})\d{3,9}(\d{3})\b/',
            fn ($m) => $m[1] . str_repeat('*', 4) . $m[2],
            $message
        ) ?? $message;

        $message = preg_replace('/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '$1***$2', $message) ?? $message;

        return $message;
    }

    /**
     * Kelas exception dan lokasi kode, kalau ada.
     *
     * @return array{0:?string, 1:?string, 2:?int}
     */
    private function locate(string $raw): array
    {
        $exception = null;
        $file = null;
        $line = null;

        // Bentuk Monolog: {"exception":"[object] (Kelas\Nama(code: 0): pesan …
        // Berhenti sebelum "(" karena "(code: 0)" menempel tanpa spasi, dan
        // backslash-nya ganda karena barisnya sudah lewat escape JSON.
        if (preg_match('/\{"exception":"\[object\] \(([^\s()]+)/', $raw, $m) === 1) {
            $exception = Str::limit(str_replace('\\\\', '\\', $m[1]), 160, '');
        }

        // "… at /path/file.php:123" (Monolog) dan "… in /path/file.php:123"
        // (fatal error PHP). Huruf drive Windows ikut diterima: tanpa itu
        // "D:\app\file.php:12" gagal terbaca karena ada ":" di path.
        if (preg_match('/\b(?:at|in)\s+((?:[A-Za-z]:)?[^\s:]+\.php):(\d+)/', $raw, $m) === 1) {
            $file = $this->shortenPath($m[1]);
            $line = (int) $m[2];
        }

        return [$exception, $file, $line];
    }

    /**
     * Path absolut server tidak berguna di dashboard dan membocorkan struktur
     * direktori deployment.
     *
     * Memotong prefix base_path() saja tidak cukup, dan ini bukan teori:
     * berkas log di lingkungan ini memuat baris yang ditulis produksi dengan
     * path /home/<akun>/chatbot-nastari/..., yang tidak akan pernah cocok
     * dengan base_path() mesin yang menjalankan pemindaian. Karena itu
     * pemotongannya berpatokan pada direktori project yang dikenali, bukan
     * pada prefix mesin lokal.
     */
    private function shortenPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());

        if (str_starts_with($path, $base)) {
            return Str::limit(ltrim(substr($path, strlen($base)), '/'), 255, '');
        }

        // Urutannya penting: berkas di dalam vendor sering memuat "/app/" lebih
        // jauh di dalam path-nya, jadi vendor diperiksa lebih dulu.
        $markers = ['/vendor/', '/app/', '/bootstrap/', '/config/', '/database/',
            '/public/', '/resources/', '/routes/', '/storage/', '/tests/'];

        foreach ($markers as $marker) {
            $at = strpos($path, $marker);

            if ($at !== false) {
                return Str::limit(ltrim(substr($path, $at), '/'), 255, '');
            }
        }

        // Tidak dikenali: simpan nama berkasnya saja, jangan direktorinya.
        return Str::limit(basename($path), 255, '');
    }

    /**
     * Kunci pengelompokan: pesan yang sama dengan angka, path, dan kutipan yang
     * berbeda harus tetap mengelompok menjadi satu.
     */
    private function normalise(string $message): string
    {
        $message = preg_replace('/\d{2,}/', 'N', $message) ?? $message;
        $message = preg_replace('/[\'"][^\'"]{0,80}[\'"]/', 'X', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return trim(mb_strtolower($message));
    }

    /**
     * Label yang bisa dibaca, memakai kosakata yang sama dengan
     * NastariAnalyticsService::errorTypeLabel() supaya kedua blok di dashboard
     * tidak memakai istilah yang berbeda untuk hal yang sama.
     */
    private function classify(string $message): string
    {
        return match (true) {
            str_contains($message, 'cURL error 28')            => 'Timeout layanan eksternal',
            str_contains($message, 'Connection refused')       => 'Kegagalan koneksi',
            str_contains($message, 'cURL error')               => 'Kegagalan koneksi',
            str_contains($message, 'Allowed memory')           => 'Memori PHP habis',
            str_contains($message, 'Maximum execution time')   => 'Batas waktu eksekusi',
            str_contains($message, 'Route [')                  => 'Route tidak terdaftar',
            str_contains($message, 'SQLSTATE')                 => 'Kegagalan database',
            str_contains($message, 'Call to undefined')        => 'Method tidak ada',
            str_contains($message, 'Class ')
                && str_contains($message, 'not found')         => 'Kelas tidak ditemukan',
            str_contains($message, 'Permission denied')        => 'Izin berkas ditolak',
            str_contains($message, 'No such file')             => 'Berkas tidak ditemukan',
            default                                            => 'Error lain',
        };
    }

    private function rememberOffset(string $name, int $offset, int $size, int $entries, bool $exists): void
    {
        $payload = [
            'last_offset'     => max(0, $offset),
            'last_size'       => $size,
            'last_scanned_at' => now(),
            'updated_at'      => now(),
        ];

        if ($exists) {
            DB::table('nastari_log_scans')->where('source_file', $name)->update(array_merge($payload, [
                'entries_seen' => DB::raw('entries_seen + ' . (int) $entries),
            ]));

            return;
        }

        DB::table('nastari_log_scans')->insert(array_merge($payload, [
            'source_file'  => $name,
            'entries_seen' => $entries,
            'created_at'   => now(),
        ]));
    }

    /**
     * @param  array<string, array<string, mixed>>  $batch
     * @return array{0:int, 1:int}
     */
    private function flush(array $batch): array
    {
        $rows = array_values($batch);

        // insertOrIgnore bersandar pada indeks unik fingerprint: memindai ulang
        // rentang byte yang sama tidak menghasilkan baris ganda.
        $inserted = DB::table('nastari_log_entries')->insertOrIgnore($rows);

        return [$inserted, count($rows) - $inserted];
    }

    private function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return DB::table('nastari_log_entries')
            ->where('entry_date', '<', Carbon::today()->subDays($days)->toDateString())
            ->delete();
    }
}
