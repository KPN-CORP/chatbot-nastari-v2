<?php

namespace Tests\Feature;

use App\Services\NastariAnalyticsService;
use App\Services\NastariEventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Riwayat tanya-jawab Pandu dan metrik yang dibaca dari sana.
 *
 * Yang diuji di sini adalah hal-hal yang membuat bagian Pandu di dashboard
 * bisa dipercaya:
 *
 *   - jawaban Pandu benar-benar tersimpan, termasuk yang dipulihkan dari
 *     baris PANDU_DEBUG di file log — sebelumnya jawaban dikirim ke WhatsApp
 *     lalu hilang, sehingga satu-satunya jejak Pandu di database adalah
 *     kegagalannya (`hears` hanya menerima eskalasi);
 *   - pertanyaan yang tidak pernah mendapat respons tetap tercatat, bukan
 *     hilang tanpa jejak;
 *   - pemulihan dari log bersifat idempoten;
 *   - tiket eskalasi hanya ditautkan pada kecocokan tepat, karena pencocokan
 *     longgar akan menempelkan jawaban HCO ke pertanyaan yang salah.
 */
class PanduQaTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private array $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nastari_pandu_' . uniqid('', false);
        mkdir($this->dir);

        // Ingestor membaca storage/logs lewat storage_path(), jadi jalur log
        // untuk pengujian diarahkan ke direktori sementara.
        app()->useStoragePath($this->dir);
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'logs');

        $this->window = ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30, 'label' => 'uji'];
    }

    protected function tearDown(): void
    {
        $logs = $this->dir . DIRECTORY_SEPARATOR . 'logs';

        foreach (glob($logs . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($logs);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function writeLog(string $content): void
    {
        file_put_contents(
            $this->dir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'laravel-2026-09-05.log',
            $content
        );
    }

    private function line(string $message, string $time = '10:15:30', string $level = 'INFO'): string
    {
        return "[2026-09-05 {$time}] local.{$level}: {$message}\n";
    }

    /** Urutan baris yang benar-benar ditulis HearService untuk satu pertanyaan. */
    private function exchange(string $phone, string $question, ?string $answer, bool $notFound = false, string $time = '10:15:30'): string
    {
        $response = $answer === null
            ? 'null'
            : json_encode(['answer' => $answer, 'not_found' => $notFound], JSON_UNESCAPED_UNICODE);

        return $this->line('PANDU_DEBUG: Request dari WA ' . $phone, $time)
            . $this->line('PANDU_DEBUG: Payload ke Python: ' . json_encode([
                'question'      => $question,
                'business_unit' => 'Downstream',
            ], JSON_UNESCAPED_UNICODE), $time)
            . $this->line('PANDU_DEBUG: Respon Python: ' . $response, $time);
    }

    private function qa(array $attrs = []): void
    {
        static $n = 0;
        $n++;

        DB::table('pandu_qa')->insert(array_merge([
            'origin'        => 'live',
            'asked_at'      => '2026-09-05 10:00:00',
            'ask_date'      => '2026-09-05',
            'ask_hour'      => 10,
            'phone'         => '628111000001',
            'employee_id'   => '000001',
            'employee_name' => 'Sri Lestari',
            'business_unit' => 'Downstream',
            'question'      => 'Pertanyaan ' . $n,
            'answer'        => 'Jawaban ' . $n,
            'outcome'       => 'answered',
            'duration_ms'   => 4000,
            'fingerprint'   => sha1('qa-fixture-' . $n),
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // pemulihan dari file log
    // ------------------------------------------------------------------

    public function test_the_answer_text_is_recovered_from_the_log(): void
    {
        $answer = 'Klaim kacamata dapat diajukan melalui menu Reimbursement di Darwinbox dengan plafon sesuai golongan.';

        $this->writeLog($this->exchange('628111000001', 'Bagaimana cara klaim kacamata', $answer));

        $result = app(NastariEventIngestor::class)->ingest();

        $this->assertSame(1, $result['pandu_qa']);

        $row = DB::table('pandu_qa')->first();

        $this->assertSame('ingest', $row->origin);
        $this->assertSame('Bagaimana cara klaim kacamata', $row->question);
        $this->assertSame($answer, $row->answer, 'teks jawaban tidak ada di tempat lain selain baris log ini');
        $this->assertSame('answered', $row->outcome);
        $this->assertSame('628111000001', $row->phone);
        $this->assertSame('Downstream', $row->business_unit);
        $this->assertSame('2026-09-05', $row->ask_date);
    }

    public function test_a_not_found_answer_keeps_its_outcome(): void
    {
        $this->writeLog($this->exchange(
            '628111000001',
            'Berapa sisa cuti saya',
            'Informasi tersebut tidak tersedia dalam dokumen kebijakan.',
            notFound: true
        ));

        app(NastariEventIngestor::class)->ingest();

        $row = DB::table('pandu_qa')->first();

        $this->assertSame('not_found', $row->outcome);
        $this->assertNotNull($row->answer, 'pesan penolakan dari RAG tetap disimpan apa adanya');
    }

    public function test_a_question_that_never_got_a_response_is_still_recorded(): void
    {
        // Pertanyaan pertama menggantung: tidak ada baris respons sebelum
        // pertanyaan berikutnya masuk. Justru yang seperti ini yang perlu
        // dilihat, jadi tidak boleh dibuang.
        $this->writeLog(
            $this->line('PANDU_DEBUG: Request dari WA 628111000001', '09:00:00')
            . $this->line('PANDU_DEBUG: Payload ke Python: ' . json_encode(['question' => 'Pertanyaan menggantung']), '09:00:01')
            . $this->exchange('628111000002', 'Pertanyaan kedua', 'Jawaban kedua', time: '09:05:00')
        );

        app(NastariEventIngestor::class)->ingest();

        $rows = DB::table('pandu_qa')->orderBy('asked_at')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Pertanyaan menggantung', $rows[0]->question);
        $this->assertSame('no_response', $rows[0]->outcome);
        $this->assertNull($rows[0]->answer);
        $this->assertSame('answered', $rows[1]->outcome);
    }

    public function test_the_last_question_in_the_file_is_not_lost(): void
    {
        $this->writeLog(
            $this->line('PANDU_DEBUG: Request dari WA 628111000001', '09:00:00')
            . $this->line('PANDU_DEBUG: Payload ke Python: ' . json_encode(['question' => 'Pertanyaan terakhir']), '09:00:01')
        );

        app(NastariEventIngestor::class)->ingest();

        $this->assertSame(1, DB::table('pandu_qa')->count());
        $this->assertSame('no_response', DB::table('pandu_qa')->value('outcome'));
    }

    public function test_recovering_twice_does_not_duplicate(): void
    {
        $this->writeLog($this->exchange('628111000001', 'Cuti pribadi itu apa', 'Cuti pribadi adalah…'));

        $ingestor = app(NastariEventIngestor::class);

        $first = $ingestor->ingest();
        $second = app(NastariEventIngestor::class)->ingest();

        $this->assertSame(1, $first['pandu_qa']);
        $this->assertSame(0, $second['pandu_qa']);
        $this->assertSame(1, DB::table('pandu_qa')->count());
    }

    public function test_an_escalation_ticket_is_linked_only_on_an_exact_match(): void
    {
        $this->qa(['question' => 'Bagaimana klaim rawat inap', 'outcome' => 'not_found', 'answer' => null]);
        $this->qa(['question' => 'Pertanyaan yang tidak pernah dieskalasi']);

        DB::table('hears')->insert([
            [
                'ticket_code' => '099',
                'date'        => '2026-09-05',
                'employee_id' => '000001',
                'name'        => 'Sri Lestari',
                'phone'       => '0811-1000-001',   // format berbeda, harus dinormalkan
                'question'    => 'Bagaimana klaim rawat inap',
                'answer'      => 'Silakan ajukan melalui HCO unit bisnis Anda.',
                'status'      => 'Done',
            ],
            [
                'ticket_code' => '100',
                'date'        => '2026-09-06',
                'employee_id' => '000001',
                'name'        => 'Sri Lestari',
                'phone'       => '628111000001',
                'question'    => 'Pertanyaan yang teksnya tidak sama persis',
                'answer'      => null,
                'status'      => 'Processing',
            ],
        ]);

        app(NastariEventIngestor::class)->ingest();

        $linked = DB::table('pandu_qa')->whereNotNull('ticket_code')->get();

        $this->assertCount(1, $linked, 'hanya kecocokan tepat yang ditautkan');
        $this->assertSame('099', $linked->first()->ticket_code);
        $this->assertSame('Bagaimana klaim rawat inap', $linked->first()->question);
    }

    // ------------------------------------------------------------------
    // metrik yang dibaca dashboard
    // ------------------------------------------------------------------

    public function test_the_overview_counts_each_outcome_and_the_answer_rate(): void
    {
        $this->qa(['outcome' => 'answered', 'duration_ms' => 2000]);
        $this->qa(['outcome' => 'answered', 'duration_ms' => 6000]);
        $this->qa(['outcome' => 'not_found', 'answer' => null, 'phone' => '628111000002']);
        $this->qa(['outcome' => 'no_response', 'answer' => null]);
        $this->qa(['outcome' => 'error', 'answer' => null, 'handoff_from' => 'ruang']);

        // Di luar jendela: tidak boleh ikut terhitung.
        $this->qa(['ask_date' => '2026-08-01', 'asked_at' => '2026-08-01 10:00:00']);

        $overview = app(NastariAnalyticsService::class)->panduOverview($this->window);

        $this->assertSame(5, $overview['total']);
        $this->assertSame(2, $overview['answered']);
        $this->assertSame(1, $overview['not_found']);
        $this->assertSame(1, $overview['no_response']);
        $this->assertSame(1, $overview['errors']);
        $this->assertSame(1, $overview['from_ruang']);
        $this->assertSame(3, $overview['answer_missing']);
        $this->assertSame(40.0, $overview['answer_rate']);
        $this->assertSame(2, $overview['users']);
        $this->assertSame(4.0, $overview['avg_seconds'], 'rata-rata hanya dari pertanyaan yang dijawab');
    }

    public function test_the_daily_series_keeps_empty_days_as_zero(): void
    {
        $this->qa(['ask_date' => '2026-09-02', 'asked_at' => '2026-09-02 10:00:00', 'outcome' => 'answered']);
        $this->qa(['ask_date' => '2026-09-02', 'asked_at' => '2026-09-02 11:00:00', 'outcome' => 'not_found', 'answer' => null]);
        $this->qa(['ask_date' => '2026-09-04', 'asked_at' => '2026-09-04 10:00:00', 'outcome' => 'error', 'answer' => null]);

        $daily = app(NastariAnalyticsService::class)->panduDaily([
            'from' => '2026-09-01', 'to' => '2026-09-04', 'days' => 4, 'label' => 'uji',
        ]);

        $this->assertCount(4, $daily);
        $this->assertSame(0, $daily[0]['total'], 'hari tanpa pertanyaan tetap muncul sebagai nol');
        $this->assertSame(2, $daily[1]['total']);
        $this->assertSame(1, $daily[1]['answered']);
        $this->assertSame(1, $daily[1]['not_found']);
        $this->assertSame(1, $daily[3]['failed']);
    }

    public function test_repeated_questions_group_together(): void
    {
        foreach (['Bagaimana cara klaim kacamata?', 'bagaimana cara klaim kacamata', 'Cara klaim kacamata ya'] as $q) {
            $this->qa(['question' => $q, 'outcome' => 'answered']);
        }

        $this->qa(['question' => 'Bagaimana cara klaim kacamata', 'outcome' => 'not_found', 'answer' => null]);
        $this->qa(['question' => 'Pertanyaan lain yang berbeda sama sekali']);

        $top = app(NastariAnalyticsService::class)->panduTopQuestions($this->window);

        $this->assertCount(1, $top, 'hanya pertanyaan yang muncul lebih dari sekali yang dilaporkan');
        $this->assertSame(4, $top[0]['count']);
        $this->assertSame(3, $top[0]['answered']);
        $this->assertSame(1, $top[0]['not_found'], 'kolom ini yang jadi kandidat penambahan knowledge base');
    }

    public function test_the_qa_table_filters_searches_and_clamps_its_page(): void
    {
        foreach (range(1, 17) as $i) {
            $this->qa([
                'question' => 'Pertanyaan tentang topik ' . $i,
                'outcome'  => $i === 1 ? 'not_found' : 'answered',
                'answer'   => $i === 1 ? null : 'Jawaban untuk topik ' . $i,
            ]);
        }

        $analytics = app(NastariAnalyticsService::class);

        $page1 = $analytics->panduConversations($this->window);
        $this->assertSame(17, $page1['total']);
        $this->assertCount(15, $page1['rows']);
        $this->assertSame(2, $page1['last_page']);

        $clamped = $analytics->panduConversations($this->window, null, null, null, 15, 99);
        $this->assertSame(2, $clamped['page']);
        $this->assertCount(2, $clamped['rows']);

        $unanswered = $analytics->panduConversations($this->window, null, 'unanswered');
        $this->assertSame(1, $unanswered['total']);
        $this->assertSame('not_found', $unanswered['rows'][0]['outcome']);

        // Pencarian menyentuh pertanyaan dan jawaban.
        $byQuestion = $analytics->panduConversations($this->window, null, null, 'topik 7');
        $this->assertSame(1, $byQuestion['total']);

        $byAnswer = $analytics->panduConversations($this->window, null, null, 'Jawaban untuk topik 9');
        $this->assertSame(1, $byAnswer['total']);

        $none = $analytics->panduConversations($this->window, null, null, 'tidak-ada-ini');
        $this->assertSame(0, $none['total']);
        $this->assertSame(1, $none['last_page']);
    }

    public function test_the_qa_table_carries_the_hco_answer_for_an_escalated_question(): void
    {
        $this->qa(['question' => 'Klaim rawat inap', 'outcome' => 'not_found', 'answer' => null, 'ticket_code' => '099']);

        DB::table('hears')->insert([
            'ticket_code' => '099',
            'date'        => '2026-09-05',
            'employee_id' => '000001',
            'name'        => 'Sri Lestari',
            'phone'       => '628111000001',
            'question'    => 'Klaim rawat inap',
            'answer'      => 'Klaim rawat inap diajukan lewat HCO unit bisnis.',
            'pic'         => 'Metta Saputra',
            'status'      => 'Done',
        ]);

        $page = app(NastariAnalyticsService::class)->panduConversations($this->window, null, 'escalated');

        $this->assertSame(1, $page['total']);

        $row = $page['rows'][0];

        $this->assertSame('099', $row['ticket_code']);
        $this->assertSame('Klaim rawat inap diajukan lewat HCO unit bisnis.', $row['ticket_answer']);
        $this->assertSame('Done', $row['ticket_status']);
        $this->assertSame('Metta Saputra', $row['ticket_pic']);
        $this->assertSame('6281****0001', $row['phone_masked'], 'nomor tetap disamarkan');
    }
}
