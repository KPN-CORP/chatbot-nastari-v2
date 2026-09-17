<?php

namespace Tests\Feature;

use App\Services\NastariAnalyticsService;
use App\Services\NastariLogScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pemindaian storage/logs ke nastari_log_entries.
 *
 * Dua hal yang diuji paling keras di sini, karena keduanya adalah alasan kelas
 * ini boleh ada sekaligus risiko terbesarnya:
 *
 *   1. **Redaksi.** URL Darwinbox membawa api_key di query string dan pesan
 *      error cURL mencetak URL utuh, jadi tanpa penyaring, memindai log berarti
 *      menyalin kredensial ke database dan menampilkannya di dashboard. Data
 *      log nyata di lingkungan ini kebetulan tidak memuat kredensial, jadi
 *      satu-satunya cara membuktikan penyaringnya bekerja adalah fixture
 *      sintetis di sini.
 *   2. **Biaya.** Pemindaian dijadwalkan tiap 10 menit; ia harus membaca byte
 *      baru saja, bukan seluruh file. Kalau tidak, logging berubah menjadi
 *      beban — hal yang justru harus dihindari.
 */
class LogScannerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private NastariLogScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nastari_logs_' . uniqid('', false);
        mkdir($this->dir);

        config([
            'nastari.logging.files.path'    => $this->dir,
            'nastari.logging.files.pattern' => 'laravel*.log',
            'nastari.logging.files.levels'  => ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'],
            'nastari.logging.files.max_message'          => 400,
            'nastari.logging.files.max_entries_per_scan' => 5000,
            'nastari.logging.files.retention_days'       => 30,
        ]);

        $this->scanner = app(NastariLogScanner::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    private function write(string $name, string $content, bool $append = false): void
    {
        file_put_contents(
            $this->dir . DIRECTORY_SEPARATOR . $name,
            $content,
            $append ? FILE_APPEND : 0
        );
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    private function line(string $level, string $message, ?string $date = null): string
    {
        return '[' . ($date ?? $this->today()) . " 10:15:30] local.{$level}: {$message}\n";
    }

    // ------------------------------------------------------------------
    // apa yang diambil
    // ------------------------------------------------------------------

    public function test_only_error_level_and_above_is_stored(): void
    {
        $this->write('laravel.log',
            $this->line('DEBUG', 'WA_INCOMING | From: 628 | Type: text | Message: halo')
            . $this->line('INFO', 'LETTER_GENERATED nomor 062/SK/PTY/09-2026')
            . $this->line('WARNING', 'LETTERHEAD_SKIPPED_OVER_BUDGET file kop.png')
            . $this->line('ERROR', 'Sesuatu gagal dijalankan')
            . $this->line('CRITICAL', 'Layanan inti tidak merespons')
        );

        $result = $this->scanner->scan();

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(
            ['CRITICAL', 'ERROR'],
            DB::table('nastari_log_entries')->orderBy('level')->pluck('level')->all()
        );
    }

    public function test_a_stack_trace_becomes_one_entry_with_its_class_and_location(): void
    {
        $this->write('laravel.log',
            $this->line('ERROR', 'Route [login] not defined. {"exception":"[object] (Symfony\\\\Component\\\\Routing\\\\Exception\\\\RouteNotFoundException(code: 0): Route [login] not defined. at ' . base_path('vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php') . ':485)')
            . "[stacktrace]\n"
            . "#0 " . base_path('app/Http/Middleware/Authenticate.php') . "(21): call_user_func()\n"
            . "#1 {main}\n"
            . "\n"
            . $this->line('ERROR', 'Kejadian berikutnya')
        );

        $this->scanner->scan();

        $rows = DB::table('nastari_log_entries')->orderBy('id')->get();

        $this->assertCount(2, $rows, 'baris stack trace bukan entri tersendiri');

        $first = $rows->first();
        $this->assertSame('Symfony\Component\Routing\Exception\RouteNotFoundException', $first->exception);
        $this->assertSame('vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php', $first->file,
            'path absolut server dipendekkan menjadi relatif terhadap project');
        $this->assertSame(485, (int) $first->line);
        $this->assertSame('Route tidak terdaftar', $first->kind);
        $this->assertStringNotContainsString('[stacktrace]', $first->message);
        $this->assertStringNotContainsString('{"exception"', $first->message);
    }

    public function test_php_fatal_errors_are_captured_with_their_location(): void
    {
        // Kelas kegagalan yang TIDAK MUNGKIN ada di nastari_events: prosesnya
        // mati sebelum pencatat aktivitas bisa menulis apa pun.
        $this->write('laravel.log', $this->line(
            'ERROR',
            'Allowed memory size of 536870912 bytes exhausted (tried to allocate 411041792 bytes) in '
                . base_path('vendor/setasign/fpdf/fpdf.php') . ':1366'
        ));

        $this->scanner->scan();

        $row = DB::table('nastari_log_entries')->first();

        $this->assertSame('Memori PHP habis', $row->kind);
        $this->assertSame('vendor/setasign/fpdf/fpdf.php', $row->file);
        $this->assertSame(1366, (int) $row->line);
    }

    // ------------------------------------------------------------------
    // redaksi
    // ------------------------------------------------------------------

    public function test_credentials_are_never_stored(): void
    {
        $secrets = [
            'api_key'       => 'abc123SUPERSECRETVALUE',
            'bearer'        => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            'password'      => 'RahasiaSekali123',
            'dataset_key'   => 'ds-9f8e7d6c5b4a',
            'long_key'      => 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8',
        ];

        $this->write('laravel.log',
            $this->line('ERROR', 'cURL error 28: Operation timed out for https://kpn.darwinbox.com/api/employee?api_key=' . $secrets['api_key'] . '&datasetKey=' . $secrets['dataset_key'])
            . $this->line('ERROR', 'Permintaan ditolak dengan header Authorization: Bearer ' . $secrets['bearer'])
            . $this->line('ERROR', 'SMTP gagal login dengan password=' . $secrets['password'])
            . $this->line('ERROR', 'Token pemulihan ' . $secrets['long_key'] . ' tidak valid')
        );

        $this->scanner->scan();

        $stored = DB::table('nastari_log_entries')->pluck('message')
            ->concat(DB::table('nastari_log_entries')->pluck('title'))
            ->implode(' || ');

        foreach ($secrets as $label => $secret) {
            $this->assertStringNotContainsString($secret, $stored, "kredensial '{$label}' tersimpan di database");
        }

        $this->assertStringContainsString('[disensor]', $stored, 'penyaring harus meninggalkan penanda');

        // Konteks yang berguna tetap ada: tanpa ini penyaringnya kebablasan dan
        // errornya jadi tidak bisa ditelusuri.
        $this->assertStringContainsString('cURL error 28', $stored);
        $this->assertStringContainsString('kpn.darwinbox.com', $stored);
        $this->assertSame(
            'Timeout layanan eksternal',
            DB::table('nastari_log_entries')->where('kind', 'Timeout layanan eksternal')->value('kind')
        );
    }

    public function test_a_path_written_by_another_server_is_still_normalised(): void
    {
        // Berkas log nyata memuat baris yang ditulis produksi. Prefix
        // base_path() mesin ini tidak akan pernah cocok, dan tanpa penanganan
        // ini struktur direktori server ikut tersimpan lalu tampil di
        // dashboard.
        $this->write('laravel.log',
            $this->line('ERROR', 'Operation timed out at /home/hcispanel/chatbot-nastari/vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:1787')
            . $this->line('ERROR', 'Gagal memuat data in /srv/deploy/current/app/Services/DarwinboxService.php:214')
            . $this->line('ERROR', 'Gagal memuat data in /opt/aneh/sekali/berkas.php:9')
        );

        $this->scanner->scan();

        $files = DB::table('nastari_log_entries')->orderBy('id')->pluck('file')->all();

        $this->assertSame('vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php', $files[0]);
        $this->assertSame('app/Services/DarwinboxService.php', $files[1]);
        $this->assertSame('berkas.php', $files[2], 'path yang tidak dikenali disisakan nama berkasnya saja');

        foreach ($files as $file) {
            $this->assertStringNotContainsString('/home/', (string) $file);
            $this->assertStringNotContainsString('/srv/', (string) $file);
            $this->assertStringNotContainsString('/opt/', (string) $file);
        }

        // Badan pesannya juga: path absolut di dalam teks tidak boleh lolos
        // hanya karena kolom file-nya sudah bersih.
        $messages = DB::table('nastari_log_entries')->pluck('message')->implode(' || ');

        $this->assertStringNotContainsString('/home/hcispanel', $messages);
        $this->assertStringNotContainsString('/srv/deploy', $messages);
        $this->assertStringContainsString('vendor/laravel/framework', $messages,
            'jejaknya tetap bisa dibaca, hanya prefiks servernya yang hilang');
    }

    public function test_personal_data_is_masked(): void
    {
        $this->write('laravel.log',
            $this->line('ERROR', 'Gagal mengirim pesan ke 628123456789 milik sri.lestari@kpn-corp.com')
        );

        $this->scanner->scan();

        $message = (string) DB::table('nastari_log_entries')->value('message');

        $this->assertStringNotContainsString('628123456789', $message);
        $this->assertStringNotContainsString('sri.lestari@kpn-corp.com', $message);
        $this->assertStringContainsString('6281****789', $message, 'nomor disamarkan, bukan dihapus');
        $this->assertStringContainsString('s***@kpn-corp.com', $message, 'domain tetap terlihat untuk penelusuran');
    }

    public function test_a_long_message_is_truncated(): void
    {
        $this->write('laravel.log', $this->line('ERROR', 'Gagal: ' . str_repeat('x', 2000)));

        $this->scanner->scan();

        $row = DB::table('nastari_log_entries')->first();

        $this->assertLessThanOrEqual(410, strlen((string) $row->message));
        $this->assertLessThanOrEqual(200, strlen((string) $row->title));
    }

    // ------------------------------------------------------------------
    // biaya dan idempotensi
    // ------------------------------------------------------------------

    public function test_a_second_scan_reads_no_bytes_and_an_appended_entry_is_picked_up(): void
    {
        $this->write('laravel.log', $this->line('ERROR', 'Kejadian pertama'));

        $first = $this->scanner->scan();
        $this->assertSame(1, $first['inserted']);
        $this->assertGreaterThan(0, $first['bytes']);

        $second = $this->scanner->scan();
        $this->assertSame(0, $second['bytes'], 'file yang tidak berubah tidak dibaca ulang sama sekali');
        $this->assertSame(0, $second['entries']);

        $this->write('laravel.log', $this->line('ERROR', 'Kejadian kedua'), true);

        $third = $this->scanner->scan();
        $this->assertSame(1, $third['entries'], 'hanya baris baru yang dibaca');
        $this->assertSame(1, $third['inserted']);
        $this->assertSame(2, DB::table('nastari_log_entries')->count());
    }

    public function test_a_truncated_or_rotated_file_is_read_from_the_start_again(): void
    {
        $this->write('laravel.log', $this->line('ERROR', 'Sebelum rotasi ' . str_repeat('y', 200)));
        $this->scanner->scan();

        // Lebih kecil daripada posisi baca terakhir: file dipotong atau
        // dirotasi. Kalau offsetnya tidak direset, isi barunya tidak akan
        // pernah terbaca.
        $this->write('laravel.log', $this->line('ERROR', 'Setelah rotasi'));

        $result = $this->scanner->scan();

        $this->assertSame(1, $result['rotated']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(2, DB::table('nastari_log_entries')->count());
    }

    public function test_rescanning_from_scratch_does_not_duplicate(): void
    {
        $this->write('laravel.log',
            $this->line('ERROR', 'Kejadian A') . $this->line('ERROR', 'Kejadian B')
        );

        $this->scanner->scan();
        $this->assertSame(2, DB::table('nastari_log_entries')->count());

        $fresh = $this->scanner->scan(fresh: true);

        $this->assertSame(2, $fresh['inserted']);
        $this->assertSame(2, DB::table('nastari_log_entries')->count(), 'membangun ulang tidak boleh menggandakan');
    }

    public function test_entries_past_retention_are_pruned(): void
    {
        $old = now()->subDays(45)->toDateString();

        $this->write('laravel.log',
            $this->line('ERROR', 'Kejadian lama', $old) . $this->line('ERROR', 'Kejadian baru')
        );

        $result = $this->scanner->scan();

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(1, $result['pruned']);
        $this->assertSame(1, DB::table('nastari_log_entries')->count());
        $this->assertSame($this->today(), DB::table('nastari_log_entries')->value('entry_date'));
    }

    public function test_all_matching_log_files_are_scanned_including_the_undated_one(): void
    {
        // Yang diminta secara khusus: laravel.log tanpa tanggal tetap ikut,
        // bukan hanya berkas harian laravel-YYYY-MM-DD.log.
        $this->write('laravel.log', $this->line('ERROR', 'Dari berkas tanpa tanggal'));
        $this->write('laravel-' . $this->today() . '.log', $this->line('ERROR', 'Dari berkas harian'));
        $this->write('worker.log', $this->line('ERROR', 'Berkas di luar pola'));

        $result = $this->scanner->scan();

        $this->assertSame(2, $result['files'], 'hanya laravel*.log yang dipindai');
        $this->assertSame(2, $result['inserted']);
        $this->assertEqualsCanonicalizing(
            ['laravel.log', 'laravel-' . $this->today() . '.log'],
            DB::table('nastari_log_entries')->pluck('source_file')->all()
        );
    }

    // ------------------------------------------------------------------
    // yang dibaca dashboard
    // ------------------------------------------------------------------

    public function test_repeated_incidents_group_into_one_signature(): void
    {
        $this->write('laravel.log',
            $this->line('ERROR', 'Gagal memuat karyawan 01123070004 dari endpoint /employee')
            . $this->line('ERROR', 'Gagal memuat karyawan 05121030001 dari endpoint /employee')
            . $this->line('ERROR', 'Gagal memuat karyawan 01124040023 dari endpoint /employee')
            . $this->line('ERROR', 'Kesalahan yang sama sekali berbeda')
        );

        $this->scanner->scan();

        $analytics = app(NastariAnalyticsService::class);
        $window = ['from' => now()->subDay()->toDateString(), 'to' => $this->today(), 'days' => 2, 'label' => 'uji'];

        $summary = $analytics->logSummary($window);

        $this->assertSame(4, $summary['total']);
        $this->assertCount(2, $summary['top'], 'tiga kejadian yang hanya beda NIK harus mengelompok jadi satu');
        $this->assertSame(3, $summary['top'][0]['count']);
        $this->assertSame(1, $summary['files']);
        $this->assertNotNull($summary['last_scan']);
    }

    public function test_the_entry_table_filters_by_level_and_clamps_its_page(): void
    {
        $lines = '';

        foreach (range(1, 18) as $i) {
            $lines .= $this->line($i === 1 ? 'CRITICAL' : 'ERROR', 'Kejadian nomor ' . $i . ' pada modul ' . $i);
        }

        $this->write('laravel.log', $lines);
        $this->scanner->scan();

        $analytics = app(NastariAnalyticsService::class);
        $window = ['from' => now()->subDay()->toDateString(), 'to' => $this->today(), 'days' => 2, 'label' => 'uji'];

        $page1 = $analytics->logEntries($window, null, null, 15, 1);
        $this->assertSame(18, $page1['total']);
        $this->assertCount(15, $page1['rows']);
        $this->assertSame(2, $page1['last_page']);

        $clamped = $analytics->logEntries($window, null, null, 15, 99);
        $this->assertSame(2, $clamped['page'], 'halaman di luar rentang dijepit');
        $this->assertCount(3, $clamped['rows']);

        $critical = $analytics->logEntries($window, 'critical', null, 15, 1);
        $this->assertSame(1, $critical['total'], 'filter level tidak peduli huruf besar-kecil');
        $this->assertSame('CRITICAL', $critical['rows'][0]['level']);
    }
}
