<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parses storage/logs/laravel*.log into the nastari_events table.
 *
 * Read-only against the log files; the only writes are inserts into
 * nastari_events / nastari_ingest_runs. Idempotent: every row carries a
 * sha1 of its source log line, so re-running never duplicates.
 *
 * Nothing here runs in the WhatsApp request path.
 */
class NastariEventIngestor
{
    private const TS = '/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.*)$/';
    private const WA = '/^WA_INCOMING \| From: (\d+) \| Type: (\S+) \| Message: (.*?)\s*$/';

    private const DEEPLINK_RUANG = 'Ada banyak hal yang ingin aku sampaikan';
    private const DEEPLINK_INTRO = 'Apakah kamu mengenalku';

    /** Interactive labels that are navigation rather than value delivery. */
    private const NAV_LABELS = ['⬅️ Kembali ke Menu Utama', 'Kembali ke Menu Utama'];
    private const END_LABELS = ['Selesai', 'Selesai ✅'];

    /** @var array<string, array{employee_id:?string, business_unit:?string}>|null */
    private ?array $identityMap = null;

    /**
     * @return array{lines:int, inserted:int, skipped:int, after_cutover:int, superseded_removed:int, files:int}
     */
    public function ingest(?string $since = null, bool $fresh = false): array
    {
        if ($fresh) {
            // Only the derived rows are rebuilt. Live rows written by
            // NastariActivityLogger have no log line to be re-read from, so
            // truncating the whole table would destroy them permanently.
            DB::table('nastari_events')->where('origin', 'ingest')->delete();

            // Aturan yang sama untuk riwayat tanya-jawab: hanya baris hasil
            // pemulihan dari log yang dibangun ulang. Baris 'live' ditulis
            // HearService dan tidak punya baris log untuk dibaca kembali, jadi
            // menghapusnya berarti menghilangkannya untuk selamanya.
            DB::table('pandu_qa')->where('origin', 'ingest')->delete();
        }

        // Once the application started recording its own events, parsing the
        // log files for the same days would count everything twice: the log
        // still contains the WA_INCOMING line for a message that already has a
        // live row. Everything from the first live day onwards is therefore off
        // limits to the parser, and the parser keeps its original job of
        // back-filling the history that predates live logging.
        $liveFrom = $this->liveCutoverDate();

        // The cutover day itself is usually already half-parsed: the ingestor
        // runs every ten minutes, so it will have picked up log lines from the
        // morning of the day live logging was deployed. Those rows are now
        // superseded by live ones and have to go, or that single day is counted
        // twice for ever. Only log-derived rows are removed.
        $supersededRemoved = 0;

        if ($liveFrom !== null) {
            $supersededRemoved = DB::table('nastari_events')
                ->where('origin', 'ingest')
                ->where('event_date', '>=', $liveFrom)
                ->delete();
        }

        $runId = DB::table('nastari_ingest_runs')->insertGetId([
            'started_at' => now(),
            'status'     => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lines = 0;
        $inserted = 0;
        $skipped = 0;
        $afterCutover = 0;
        $files = 0;
        $batch = [];
        $qaBatch = [];          // pandu_qa rows recovered from the log
        $qaInserted = 0;
        $qaLinked = 0;
        $pendingPandu = null;   // question awaiting its RAG response
        $panduRequester = [];   // identity of the person who asked it

        try {
            foreach ($this->logFiles() as $file) {
                $handle = @fopen($file, 'r');
                if ($handle === false) {
                    continue;
                }
                $files++;

                while (($raw = fgets($handle)) !== false) {
                    if (! preg_match(self::TS, $raw, $m)) {
                        continue; // stack-trace continuation
                    }
                    $lines++;

                    [, $date, $time, $level, $message] = $m;

                    if ($since !== null && $date < $since) {
                        continue;
                    }

                    if ($liveFrom !== null && $date >= $liveFrom) {
                        $afterCutover++;
                        continue;
                    }

                    $event = $this->classify($date, $time, $level, $message);

                    if ($event === null) {
                        continue;
                    }

                    // A RAG response carries no row of its own: it resolves the
                    // question that preceded it. That question is usually still
                    // in the unflushed batch, so patch there first and only fall
                    // back to an UPDATE if it has already been written.
                    if (isset($event['__pandu_requester'])) {
                        $panduRequester = $this->identity((string) $event['__pandu_requester']);
                        continue;
                    }

                    if (isset($event['__pandu_response'])) {
                        $this->resolvePandu(
                            $pendingPandu,
                            $event['__pandu_response'],
                            (string) $event['occurred_at'],
                            $batch
                        );

                        // Jawabannya juga dipulihkan, bukan hanya hasilnya.
                        // Sampai sekarang teks jawaban Pandu tidak tersimpan di
                        // mana pun kecuali di baris log ini.
                        $qaRow = $this->panduQaRow(
                            $pendingPandu,
                            (string) $event['__pandu_response'],
                            $event['__pandu_answer'] ?? null,
                            (string) $event['occurred_at']
                        );

                        if ($qaRow !== null) {
                            $qaBatch[$qaRow['fingerprint']] = $qaRow;
                        }

                        $pendingPandu = null;
                        continue;
                    }

                    $event['fingerprint'] = sha1(trim($raw));

                    if ($event['event_type'] === 'pandu_question') {
                        // Unit bisnis yang dipakai RAG untuk membatasi
                        // pencarian ada di baris payload. Hasil lookup
                        // identitas menimpanya dengan null untuk nomor yang
                        // tidak dikenal, jadi nilainya disimpan dulu dan
                        // dipakai sebagai cadangan untuk baris tanya-jawab.
                        $payloadBu = $event['business_unit'] ?? null;

                        // The question line carries no phone; the Request line
                        // just before it does.
                        if ($panduRequester !== []) {
                            $event = array_merge($event, $panduRequester);
                            $panduRequester = [];
                        }

                        // Pertanyaan sebelumnya yang tidak pernah mendapat baris
                        // respons tetap dicatat sebagai no_response, bukan
                        // dibuang: pertanyaan yang menggantung justru yang perlu
                        // dilihat.
                        if ($pendingPandu !== null) {
                            $qaRow = $this->panduQaRow($pendingPandu, 'no_response', null, (string) $pendingPandu['ts']);

                            if ($qaRow !== null) {
                                $qaBatch[$qaRow['fingerprint']] = $qaRow;
                            }
                        }

                        $decodedPayload = json_decode((string) ($event['payload'] ?? '{}'), true);

                        $pendingPandu = [
                            'ts'            => $event['occurred_at'],
                            'fingerprint'   => $event['fingerprint'],
                            'question'      => is_array($decodedPayload) ? ($decodedPayload['question'] ?? '') : '',
                            'business_unit' => $event['business_unit'] ?? $payloadBu,
                            'phone'         => $event['phone'] ?? null,
                            'employee_id'   => $event['employee_id'] ?? null,
                        ];
                    }

                    $batch[$event['fingerprint']] = $event;

                    if (count($batch) >= 500) {
                        [$i, $s] = $this->flush($batch);
                        $inserted += $i;
                        $skipped += $s;
                        $batch = [];
                    }

                    if (count($qaBatch) >= 200) {
                        $qaInserted += $this->flushPanduQa($qaBatch);
                        $qaBatch = [];
                    }
                }

                fclose($handle);
            }

            // Pertanyaan terakhir di berkas terakhir juga tidak boleh hilang.
            if ($pendingPandu !== null) {
                $qaRow = $this->panduQaRow($pendingPandu, 'no_response', null, (string) $pendingPandu['ts']);

                if ($qaRow !== null) {
                    $qaBatch[$qaRow['fingerprint']] = $qaRow;
                }

                $pendingPandu = null;
            }

            if ($batch !== []) {
                [$i, $s] = $this->flush($batch);
                $inserted += $i;
                $skipped += $s;
            }

            if ($qaBatch !== []) {
                $qaInserted += $this->flushPanduQa($qaBatch);
            }

            $qaLinked = $this->linkEscalationTickets();

            DB::table('nastari_ingest_runs')->where('id', $runId)->update([
                'finished_at'     => now(),
                'lines_read'      => $lines,
                'events_inserted' => $inserted,
                'events_skipped'  => $skipped,
                'status'          => 'ok',
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('nastari_ingest_runs')->where('id', $runId)->update([
                'finished_at' => now(),
                'status'      => 'failed',
                'message'     => Str::limit($e->getMessage(), 500),
                'updated_at'  => now(),
            ]);
            throw $e;
        }

        return [
            'lines'              => $lines,
            'inserted'           => $inserted,
            'skipped'            => $skipped,
            'after_cutover'      => $afterCutover,
            'superseded_removed' => $supersededRemoved,
            'pandu_qa'           => $qaInserted,
            'pandu_qa_linked'    => $qaLinked,
            'files'              => $files,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * First date on which the application recorded its own events, or null
     * while nothing has been recorded live yet.
     */
    private function liveCutoverDate(): ?string
    {
        $earliest = DB::table('nastari_events')
            ->where('origin', 'live')
            ->min('event_date');

        return $earliest === null ? null : (string) $earliest;
    }

    private function logFiles(): array
    {
        $dir = storage_path('logs');

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'laravel*.log') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function classify(string $date, string $time, string $level, string $message)
    {
        $occurred = $date . ' ' . $time;
        $base = $this->baseRow($occurred, $date, $time);

        if (preg_match(self::WA, $message, $w)) {
            [, $phone, $type, $body] = $w;
            $body = trim($body);

            $row = array_merge($base, $this->identity($phone), [
                'source'       => 'wa_incoming',
                'message_type' => $type,
            ]);

            return array_merge($row, $this->classifyMessage($type, $body));
        }

        if (str_starts_with($message, 'Mencari Karyawan')) {
            preg_match('/No WA: (\d+)/', $message, $p);

            return array_merge($base, $this->identity($p[1] ?? ''), [
                'source'     => 'lookup',
                'event_type' => 'employee_lookup_started',
            ]);
        }

        if (str_starts_with($message, 'Karyawan DITEMUKAN')) {
            // The NIK is deliberately not stored here: it is already available
            // through the phone -> usernastari join, and this keeps the raw
            // identifier out of the analytics store.
            return array_merge($base, [
                'source'     => 'lookup',
                'event_type' => 'employee_lookup_success',
                'outcome'    => 'success',
            ]);
        }

        if (preg_match('/^Karyawan TIDAK DITEMUKAN.*No: (\d+)/', $message, $r)) {
            return array_merge($base, $this->identity($r[1]), [
                'source'            => 'lookup',
                'event_type'        => 'employee_lookup_failed',
                'outcome'           => 'not_found',
                'is_known_employee' => false,
            ]);
        }

        if (preg_match('/^PANDU_DEBUG: Request dari WA (\d+)/', $message, $p)) {
            return ['__pandu_requester' => $p[1]];
        }

        if (preg_match('/^PANDU_DEBUG: Payload ke Python: (\{.*\})\s*$/', $message, $p)) {
            $decoded = json_decode($p[1], true);

            if (! is_array($decoded) || ! isset($decoded['question'])) {
                return null;
            }

            $row = array_merge($base, [
                'source'        => 'pandu',
                'event_type'    => 'pandu_question',
                'outcome'       => 'no_response',
                'business_unit' => $decoded['business_unit'] ?? null,
                'payload'       => json_encode([
                    'question' => Str::limit((string) $decoded['question'], 500, ''),
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $row;
        }

        if (str_starts_with($message, 'PANDU_DEBUG: Respon Python:')) {
            $rawResponse = trim(substr($message, strlen('PANDU_DEBUG: Respon Python:')));
            $outcome = 'error';

            $answer = null;

            if ($rawResponse !== '' && $rawResponse !== 'null') {
                $decoded = json_decode($rawResponse, true);
                if (is_array($decoded)) {
                    $outcome = ! empty($decoded['not_found']) ? 'not_found' : 'answered';
                    $answer = isset($decoded['answer']) ? (string) $decoded['answer'] : null;
                }
            }

            return [
                '__pandu_response' => $outcome,
                '__pandu_answer'   => $answer,
                'occurred_at'      => $occurred,
            ];
        }

        if ($level === 'ERROR' || $level === 'CRITICAL') {
            return array_merge($base, [
                'source'        => 'error',
                'event_type'    => 'error',
                'feature_label' => $this->errorKind($message),
                'payload'       => json_encode([
                    'message' => $this->shortenError($message),
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function classifyMessage(string $type, string $body): array
    {
        if ($type !== 'interactive') {
            if ($type === 'text') {
                if (str_contains($body, self::DEEPLINK_RUANG)) {
                    return $this->feature('deeplink_ruang', 'Ruang (deep link)');
                }
                if (str_contains($body, self::DEEPLINK_INTRO)) {
                    return $this->feature('deeplink_intro', 'Perkenalan (deep link)');
                }

                return ['event_type' => 'text_message'];
            }

            return ['event_type' => 'media_message'];
        }

        $label = $this->sanitiseLabel($this->label($body));

        // Some log lines contain an embedded newline or a second timestamp,
        // which previously leaked a raw log fragment in as a "feature".
        if ($label === '' || mb_strlen($label) > 60 || preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $label)) {
            return ['event_type' => 'text_message'];
        }

        if (in_array($label, self::NAV_LABELS, true)) {
            return ['event_type' => 'navigation', 'feature_key' => 'back_to_main', 'feature_label' => $label];
        }

        if (in_array($label, self::END_LABELS, true)) {
            return ['event_type' => 'session_end', 'feature_key' => 'session_end', 'feature_label' => 'Selesai'];
        }

        if ($label === 'Ya, Buatkan Tiket') {
            return ['event_type' => 'pandu_escalation_accepted', 'feature_key' => 'pandu', 'feature_label' => 'Pandu — buat tiket'];
        }

        if ($label === 'Tidak, Terima Kasih') {
            return ['event_type' => 'pandu_escalation_declined', 'feature_key' => 'pandu', 'feature_label' => 'Pandu — tolak tiket'];
        }

        if (str_starts_with($body, '[NFM Form Submit]')) {
            return ['event_type' => 'feature_completed', 'feature_key' => 'leave_approval', 'feature_label' => 'Persetujuan Cuti (submit)'];
        }

        // "1. Data Karyawan" is a main-menu step; the number changes over time
        // as the menu is reordered, so it is stripped before slugging.
        if (preg_match('/^\d+\.\s*(.+)$/u', $label, $m)) {
            $clean = trim($m[1]);

            return array_merge(
                $this->feature($this->slug($clean), $clean),
                ['event_type' => 'menu_opened']
            );
        }

        return array_merge(
            $this->feature($this->slug($label), $label),
            ['event_type' => 'feature_selected']
        );
    }

    /** @return array<string,mixed> */
    private function feature(string $key, string $label): array
    {
        return ['event_type' => 'feature_selected', 'feature_key' => $key, 'feature_label' => $label];
    }

    private function sanitiseLabel(string $label): string
    {
        $label = preg_replace('/[ -]+/u', ' ', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return trim($label);
    }

    private function label(string $body): string
    {
        foreach (['[List Select] ' => 14, '[Button Click] ' => 15] as $prefix => $len) {
            if (str_starts_with($body, $prefix)) {
                return trim(substr($body, $len));
            }
        }

        return $body;
    }

    private function slug(string $label): string
    {
        $slug = Str::slug($label, '_');

        return $slug !== '' ? Str::limit($slug, 58, '') : 'lain_lain';
    }

    /** @return array<string,mixed> */
    private function baseRow(string $occurred, string $date, string $time): array
    {
        $carbon = Carbon::parse($occurred);

        return [
            'occurred_at'       => $occurred,
            'event_date'        => $date,
            'event_hour'        => (int) substr($time, 0, 2),
            'event_dow'         => (int) $carbon->isoWeekday(),
            'phone'             => null,
            'employee_id'       => null,
            'business_unit'     => null,
            'is_known_employee' => false,
            'feature_key'       => null,
            'feature_label'     => null,
            'message_type'      => null,
            'outcome'           => null,
            'duration_ms'       => null,
            'payload'           => null,
            'created_at'        => now(),
        ];
    }

    /**
     * Whole usernastari phone map, loaded once per run.
     *
     * Ingest touches hundreds of distinct numbers; querying per number is an
     * N+1 that dominates runtime against a remote database.
     *
     * Pre-registered rows are included deliberately. They are seeded from the
     * HRIS master with unambiguous, unclaimed numbers, so they can only improve
     * the phone -> employee hit rate for historical events; excluding them
     * would leave real employees unattributed.
     *
     * @return array<string, array{employee_id:?string, business_unit:?string}>
     */
    private function identityMap(): array
    {
        if ($this->identityMap !== null) {
            return $this->identityMap;
        }

        $this->identityMap = [];

        DB::table('usernastari')
            ->select('whatsapp_number', 'employee_id', 'group_company')
            ->whereNotNull('whatsapp_number')
            ->orderBy('id')
            ->chunk(1000, function ($rows) {
                foreach ($rows as $row) {
                    $this->identityMap[(string) $row->whatsapp_number] = [
                        'employee_id'   => $row->employee_id,
                        'business_unit' => $row->group_company,
                    ];
                }
            });

        return $this->identityMap;
    }

    /**
     * Resolves a phone number to an employee + business unit via usernastari.
     * This is what makes behavioural events filterable by business unit.
     *
     * @return array<string,mixed>
     */
    private function identity(string $phone): array
    {
        $phone = $this->cleanPhone($phone);

        if ($phone === '') {
            return [];
        }

        $found = $this->identityMap()[$phone]
            ?? ['employee_id' => null, 'business_unit' => null];

        return [
            'phone'             => $phone,
            'employee_id'       => $found['employee_id'],
            'business_unit'     => $found['business_unit'],
            'is_known_employee' => $found['employee_id'] !== null,
        ];
    }

    /**
     * The RAG answer arrives on a later log line than the question, so the
     * already-written question row is updated with its outcome and latency.
     */
    private function resolvePandu(?array $pending, string $outcome, string $responseAt, array &$batch): void
    {
        if ($pending === null) {
            return;
        }

        $durationMs = max(0, (strtotime($responseAt) - strtotime($pending['ts'])) * 1000);
        $key = $pending['fingerprint'];

        if ($key !== null && isset($batch[$key])) {
            $batch[$key]['outcome'] = $outcome;
            $batch[$key]['duration_ms'] = $durationMs;

            return;
        }

        DB::table('nastari_events')
            ->where('event_type', 'pandu_question')
            ->where('occurred_at', $pending['ts'])
            ->where('outcome', 'no_response')
            ->update([
                'outcome'     => $outcome,
                'duration_ms' => $durationMs,
            ]);
    }

    /**
     * Bentuk nomor yang sama dengan yang disimpan database.
     *
     * Mengikuti aturan DarwinboxService::cleanMobileNumber(); kelas ini tidak
     * menyuntikkan service itu karena ia tidak boleh menyentuh jalur WhatsApp.
     */
    private function cleanPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if ($phone === '') {
            return '';
        }

        if (str_starts_with($phone, '620')) {
            return '62' . substr($phone, 3);
        }

        if (str_starts_with($phone, '0')) {
            return '62' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Menautkan tiket eskalasi lama ke baris tanya-jawab yang memicunya.
     *
     * Pertanyaan yang dieskalasi setelah ini ditautkan langsung oleh
     * HearService. Yang di sini hanya riwayat, dan **hanya dengan kecocokan
     * tepat** pada nomor beserta teks pertanyaan: pencocokan longgar akan
     * menempelkan jawaban HCO ke pertanyaan yang salah, yang lebih buruk
     * daripada tidak menautkan sama sekali. Dari 37 tiket yang ada, 4 cocok
     * tepat, 13 pertanyaannya lebih tua daripada berkas log yang masih ada.
     */
    private function linkEscalationTickets(): int
    {
        $unlinked = DB::table('pandu_qa')
            ->whereNull('ticket_code')->whereNotNull('phone')
            ->orderByDesc('id')->limit(2000)
            ->get(['id', 'phone', 'question']);

        if ($unlinked->isEmpty()) {
            return 0;
        }

        $index = [];

        foreach ($unlinked as $row) {
            $key = $row->phone . '|' . trim((string) $row->question);
            $index[$key] ??= (int) $row->id;
        }

        $tickets = DB::table('hears')
            ->whereNotNull('ticket_code')->whereNotNull('phone')
            ->orderByDesc('id')->limit(500)
            ->get(['ticket_code', 'phone', 'question']);

        $linked = 0;

        foreach ($tickets as $ticket) {
            $key = $this->cleanPhone((string) $ticket->phone) . '|' . trim((string) $ticket->question);
            $id = $index[$key] ?? null;

            if ($id === null) {
                continue;
            }

            DB::table('pandu_qa')->where('id', $id)->whereNull('ticket_code')->update([
                'ticket_code' => $ticket->ticket_code,
                'updated_at'  => now(),
            ]);

            unset($index[$key]);
            $linked++;
        }

        return $linked;
    }

    /**
     * Membentuk satu baris pandu_qa dari pertanyaan yang tertunda.
     *
     * Mengembalikan null kalau pertanyaannya kosong — baris log tanpa teks
     * pertanyaan tidak berguna sebagai riwayat tanya-jawab.
     *
     * @param  array<string, mixed>|null  $pending
     * @return array<string, mixed>|null
     */
    private function panduQaRow(?array $pending, string $outcome, ?string $answer, string $occurred): ?array
    {
        if ($pending === null) {
            return null;
        }

        $question = trim((string) ($pending['question'] ?? ''));

        if ($question === '') {
            return null;
        }

        $askedAt = (string) ($pending['ts'] ?? $occurred);

        return [
            'origin'        => 'ingest',
            'asked_at'      => $askedAt,
            'ask_date'      => substr($askedAt, 0, 10),
            'ask_hour'      => (int) substr($askedAt, 11, 2),
            'phone'         => $pending['phone'] ?? null,
            'employee_id'   => $pending['employee_id'] ?? null,
            'employee_name' => null,
            'business_unit' => $pending['business_unit'] ?? null,
            'question'      => mb_substr($question, 0, 2000),
            'answer'        => $answer === null ? null : mb_substr(trim($answer), 0, 8000),
            'outcome'       => $outcome,
            'duration_ms'   => $this->panduDuration($askedAt, $occurred),
            'handoff_from'  => null,
            'ticket_code'   => null,
            // Sidik jari dari baris pertanyaannya sendiri, sehingga memindai
            // ulang berkas log yang sama tidak pernah menduplikasi.
            'fingerprint'   => sha1('qa|' . (string) ($pending['fingerprint'] ?? $askedAt . $question)),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
    }

    /**
     * Selisih antara baris pertanyaan dan baris responsnya, dalam milidetik.
     *
     * Resolusi log hanya satu detik, jadi angkanya kasar; dikembalikan null
     * kalau tidak masuk akal (negatif, atau lebih dari lima menit yang berarti
     * kedua baris itu sebenarnya bukan pasangan).
     */
    private function panduDuration(string $askedAt, string $respondedAt): ?int
    {
        $start = strtotime($askedAt);
        $end = strtotime($respondedAt);

        if ($start === false || $end === false) {
            return null;
        }

        $seconds = $end - $start;

        return ($seconds < 0 || $seconds > 300) ? null : $seconds * 1000;
    }

    /**
     * @param  array<string, array<string, mixed>>  $batch
     */
    private function flushPanduQa(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        // Baris 'live' yang ditulis HearService punya sidik jari berbeda dan
        // tidak pernah ditimpa dari sini; log hanya mengisi riwayat.
        return DB::table('pandu_qa')->insertOrIgnore(array_values($batch));
    }

    private function errorKind(string $message): string
    {
        return match (true) {
            str_contains($message, 'cURL error 28')      => 'Timeout layanan eksternal',
            str_contains($message, 'cURL error')         => 'Kegagalan koneksi',
            str_contains($message, 'Allowed memory')     => 'Memori PHP habis',
            str_contains($message, 'Route [')            => 'Route tidak terdaftar',
            str_contains($message, 'undefined method')   => 'Method tidak ada',
            default                                      => 'Error lain',
        };
    }

    private function shortenError(string $message): string
    {
        $message = preg_replace('/\s*\{"exception".*$/s', '', $message) ?? $message;
        $message = preg_replace('/\(see https?:\/\/\S+\)/', '', $message) ?? $message;
        $message = preg_replace('/\d{4,}/', 'N', $message) ?? $message;

        return Str::limit(trim($message), 180, '');
    }

    /**
     * @param  array<string, array<string,mixed>>  $batch
     * @return array{0:int, 1:int}
     */
    private function flush(array $batch): array
    {
        $rows = array_values($batch);

        // insertOrIgnore relies on the unique fingerprint index for dedupe and
        // returns the number of rows actually written.
        $inserted = DB::table('nastari_events')->insertOrIgnore($rows);

        return [$inserted, count($rows) - $inserted];
    }
}
