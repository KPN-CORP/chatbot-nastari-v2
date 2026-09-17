<?php

namespace Tests\Feature;

use App\Services\NastariAnalyticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Metrik yang menopang dashboard monitoring.
 *
 * Yang diuji di sini adalah hal-hal yang kalau rusak akan membuat dashboard
 * berbohong tanpa terlihat rusak:
 *
 *   - agregat kondisional hasil penggabungan query (pesan, aksi, navigasi,
 *     pengguna aktif) harus tetap menghasilkan angka yang sama seperti ketika
 *     masing-masing dihitung dengan COUNT sendiri;
 *   - pemisahan aktif / nonaktif / belum diketahui, dan pre-registrasi yang
 *     tidak boleh ikut terhitung sebagai pengguna terdaftar;
 *   - paginasi yang menjepit halaman di luar rentang, bukan menyajikan tabel
 *     kosong;
 *   - batasan scope unit bisnis pada tabel per karyawan;
 *   - status percakapan Ruang, di mana hanya nilai selain "ok" yang boleh
 *     terhitung sebagai bermasalah.
 *
 * Kedua koneksi diarahkan ke satu file SQLite sementara, sama seperti
 * EmployeeSyncTest: database :memory: tidak dibagi antar koneksi.
 */
class DashboardMetricsTest extends TestCase
{
    private string $dbPath;

    private NastariAnalyticsService $analytics;

    /** Jendela yang mencakup seluruh data uji. */
    private array $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nastari_dash_' . uniqid('', false) . '.sqlite';
        touch($this->dbPath);

        $connection = [
            'driver'                  => 'sqlite',
            'database'                => $this->dbPath,
            'prefix'                  => '',
            'foreign_key_constraints' => false,
        ];

        config([
            'database.connections.nastari_test' => $connection,
            'database.connections.kpncorp'      => $connection,
            'database.default'                  => 'nastari_test',
        ]);

        DB::purge('nastari_test');
        DB::purge('kpncorp');

        $this->artisan('migrate', ['--database' => 'nastari_test', '--force' => true])->run();

        Schema::connection('kpncorp')->create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->nullable();
            $table->string('group_company')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        $this->analytics = app(NastariAnalyticsService::class);
        $this->window = ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30, 'label' => 'uji'];

        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::purge('nastari_test');
        DB::purge('kpncorp');
        @unlink($this->dbPath);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function event(array $attrs = []): void
    {
        static $n = 0;
        $n++;

        $at = $attrs['occurred_at'] ?? '2026-09-10 13:30:00';

        DB::table('nastari_events')->insert(array_merge([
            'occurred_at'       => $at,
            'event_date'        => substr($at, 0, 10),
            'event_hour'        => (int) substr($at, 11, 2),
            'event_dow'         => (int) \Carbon\Carbon::parse($at)->isoWeekday(),
            'origin'            => 'live',
            'source'            => 'wa_incoming',
            'event_type'        => 'feature_selected',
            'phone'             => '628111000001',
            'is_known_employee' => true,
            'fingerprint'       => sha1('fixture-' . $n),
        ], $attrs));
    }

    private function user(string $employeeId, array $attrs = []): void
    {
        DB::table('usernastari')->insert(array_merge([
            'employee_id'         => $employeeId,
            'whatsapp_number'     => '6281110000' . substr($employeeId, -2),
            'full_name'           => 'Karyawan ' . $employeeId,
            'group_company'       => 'Downstream',
            'registration_status' => 'registered',
            'employment_status'   => 'active',
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // agregat gabungan
    // ------------------------------------------------------------------

    public function test_overview_counts_messages_actions_navigation_and_active_users_separately(): void
    {
        // Dua pesan yang mengandung aksi, satu navigasi, satu pesan biasa,
        // dari dua nomor yang dikenali dan satu yang tidak.
        $this->event(['event_type' => 'feature_selected', 'phone' => '628111000001']);
        $this->event(['event_type' => 'feature_completed', 'phone' => '628111000002']);
        $this->event(['event_type' => 'menu_opened', 'phone' => '628111000001']);
        $this->event(['event_type' => 'text_message', 'phone' => '628111000003', 'is_known_employee' => false]);

        // Aksi tanpa source wa_incoming tetap terhitung sebagai aksi, sama
        // seperti perilaku sebelumnya, tapi bukan sebagai pesan.
        $this->event(['source' => 'pandu', 'event_type' => 'pandu_escalation_accepted']);

        $overview = $this->analytics->overview($this->window);

        $this->assertSame(4, $overview['messages'], 'pesan hanya menghitung source wa_incoming');
        $this->assertSame(3, $overview['actions']);
        $this->assertSame(1, $overview['navigation']);
        $this->assertSame(2, $overview['active_users'], 'nomor yang tidak dikenali tidak dihitung sebagai pengguna aktif');
    }

    public function test_unknown_number_counts_separate_distinct_numbers_from_attempts(): void
    {
        $this->event(['source' => 'lookup', 'event_type' => 'employee_lookup_failed', 'phone' => '628999000001', 'is_known_employee' => false]);
        $this->event(['source' => 'lookup', 'event_type' => 'employee_lookup_failed', 'phone' => '628999000001', 'is_known_employee' => false]);
        $this->event(['source' => 'lookup', 'event_type' => 'employee_lookup_failed', 'phone' => '628999000002', 'is_known_employee' => false]);

        $overview = $this->analytics->overview($this->window);
        $unknown = $this->analytics->unknownNumbers($this->window);

        $this->assertSame(2, $overview['unknown_numbers']);
        $this->assertSame(3, $overview['unknown_attempts']);
        $this->assertSame(2, $unknown['unique_numbers']);
        $this->assertSame(3, $unknown['total_attempts']);
        $this->assertSame(1, $unknown['repeat_offenders'], 'hanya satu nomor yang mencoba lebih dari sekali');
        $this->assertCount(2, $unknown['rows']);
        $this->assertSame('6289****0001', $unknown['rows'][0]['phone_masked'], 'nomor harus disamarkan');
    }

    public function test_busy_hours_report_the_peak_and_stay_empty_without_traffic(): void
    {
        $this->assertNull(
            $this->analytics->peakHour($this->analytics->byHour($this->window)),
            'tanpa pesan tidak boleh ada jam sibuk yang dilaporkan'
        );

        $this->event(['occurred_at' => '2026-09-10 09:15:00', 'phone' => '628111000001']);
        $this->event(['occurred_at' => '2026-09-10 14:15:00', 'phone' => '628111000001']);
        $this->event(['occurred_at' => '2026-09-10 14:45:00', 'phone' => '628111000002']);

        $hours = $this->analytics->byHour($this->window);
        $peak = $this->analytics->peakHour($hours);

        $this->assertCount(24, $hours, 'jam tanpa aktivitas tetap muncul sebagai nol');
        $this->assertSame(14, $peak['hour']);
        $this->assertSame('14:00', $peak['label']);
        $this->assertSame(2, $peak['messages']);
        $this->assertSame(2, $peak['users']);
        $this->assertSame(1, $hours[9]['messages']);
        $this->assertSame(0, $hours[0]['messages']);
    }

    // ------------------------------------------------------------------
    // (a) aktif vs nonaktif
    // ------------------------------------------------------------------

    public function test_coverage_splits_employment_status_and_excludes_pre_registered(): void
    {
        $this->user('000001', ['employment_status' => 'active']);
        $this->user('000002', ['employment_status' => 'active']);
        $this->user('000003', ['employment_status' => 'inactive']);
        $this->user('000004', ['employment_status' => 'unknown']);
        $this->user('000005', ['registration_status' => 'pre_registered', 'employment_status' => 'active']);

        DB::connection('kpncorp')->table('employees')->insert([
            ['employee_id' => '000001', 'group_company' => 'Downstream', 'deleted_at' => null],
            ['employee_id' => '000002', 'group_company' => 'Downstream', 'deleted_at' => null],
            ['employee_id' => '000003', 'group_company' => 'Downstream', 'deleted_at' => '2026-08-01 00:00:00'],
        ]);

        $coverage = $this->analytics->registrationCoverage(null);

        $this->assertSame(2, $coverage['active']);
        $this->assertSame(1, $coverage['inactive']);
        $this->assertSame(1, $coverage['unknown']);
        $this->assertSame(4, $coverage['registered'], 'pre-registered tidak boleh ikut jadi pengguna terdaftar');
        $this->assertSame(1, $coverage['pre_registered']);
        $this->assertSame(2, $coverage['headcount_active'], 'baris ter-soft-delete tidak masuk headcount');
        $this->assertSame(200.0, $coverage['coverage_pct']);
    }

    public function test_access_denials_are_counted_and_attributed_to_a_feature(): void
    {
        $this->event([
            'occurred_at'   => '2026-09-10 08:00:00',
            'event_type'    => 'access_denied',
            'action_key'    => 'employee.access.denied',
            'employee_id'   => '000003',
            'feature_key'   => 'leave_balance',
            'feature_label' => 'Saldo Cuti',
            'outcome'       => 'denied',
        ]);

        // Penolakan lewat state, jalur yang dipakai ketika karyawan nonaktif
        // melanjutkan alur yang dimulainya saat masih aktif.
        $this->event([
            'occurred_at'   => '2026-09-10 09:00:00',
            'event_type'    => 'access_denied',
            'employee_id'   => '000003',
            'feature_key'   => 'state:waiting_for_letter',
            'feature_label' => 'Sesi dihentikan (nonaktif)',
        ]);

        $this->event(['event_type' => 'feature_selected']);

        $denials = $this->analytics->accessDenials($this->window);

        $this->assertSame(2, $denials['total'], 'hanya event access_denied yang dihitung');
        $this->assertSame(1, $denials['employees']);
        $this->assertCount(2, $denials['by_feature']);

        // Terbaru lebih dulu.
        $this->assertSame('Sesi dihentikan (nonaktif)', $denials['recent'][0]['feature']);
        $this->assertSame('Saldo Cuti', $denials['recent'][1]['feature']);
    }

    // ------------------------------------------------------------------
    // (h) akses fitur per karyawan
    // ------------------------------------------------------------------

    public function test_employee_feature_usage_groups_per_employee_and_names_the_top_feature(): void
    {
        $this->user('000001', ['full_name' => 'Sri Lestari']);
        $this->user('000002', ['full_name' => 'Bagus Pratama', 'employment_status' => 'inactive']);

        foreach (range(1, 3) as $i) {
            $this->event(['employee_id' => '000001', 'feature_key' => 'personal_data', 'feature_label' => 'Data Karyawan']);
        }

        $this->event(['employee_id' => '000001', 'feature_key' => 'leave_balance', 'feature_label' => 'Saldo Cuti']);
        $this->event(['employee_id' => '000002', 'feature_key' => 'personal_data', 'feature_label' => 'Data Karyawan']);

        // Navigasi bukan aksi: tidak boleh menambah hitungan siapa pun.
        $this->event(['employee_id' => '000002', 'event_type' => 'menu_opened', 'feature_key' => 'main_menu']);

        // Event tanpa employee_id tidak bisa diatribusikan ke orang.
        $this->event(['employee_id' => null, 'feature_key' => 'personal_data']);

        $usage = $this->analytics->employeeFeatureUsage($this->window);

        $this->assertSame(2, $usage['total']);
        $this->assertSame(5, $usage['total_actions']);

        [$first, $second] = $usage['rows'];

        $this->assertSame('000001', $first['employee_id']);
        $this->assertSame('Sri Lestari', $first['name']);
        $this->assertSame(4, $first['actions']);
        $this->assertSame(2, $first['features']);
        $this->assertSame('Data Karyawan', $first['top_feature']);
        $this->assertSame(3, $first['top_uses']);
        $this->assertSame('active', $first['status']);

        $this->assertSame('000002', $second['employee_id']);
        $this->assertSame(1, $second['actions'], 'menu_opened tidak dihitung sebagai aksi');
        $this->assertSame('inactive', $second['status'], 'status diambil dari usernastari, bukan dari baris event');
    }

    public function test_employee_feature_usage_clamps_a_page_beyond_the_last_one(): void
    {
        $this->user('000001');
        $this->event(['employee_id' => '000001']);

        $usage = $this->analytics->employeeFeatureUsage($this->window, null, null, 25, 99);

        $this->assertSame(1, $usage['page'], 'halaman di luar rentang dijepit ke halaman terakhir');
        $this->assertSame(1, $usage['last_page']);
        $this->assertCount(1, $usage['rows'], 'tidak boleh menyajikan halaman kosong');
    }

    public function test_employee_feature_usage_can_be_searched_by_name_or_nik(): void
    {
        $this->user('000001', ['full_name' => 'Sri Lestari']);
        $this->user('000002', ['full_name' => 'Bagus Pratama']);
        $this->event(['employee_id' => '000001']);
        $this->event(['employee_id' => '000002']);

        $byName = $this->analytics->employeeFeatureUsage($this->window, null, 'lestari');
        $this->assertSame(1, $byName['total']);
        $this->assertSame('Sri Lestari', $byName['rows'][0]['name']);

        $byNik = $this->analytics->employeeFeatureUsage($this->window, null, '000002');
        $this->assertSame(1, $byNik['total']);
        $this->assertSame('000002', $byNik['rows'][0]['employee_id']);

        $none = $this->analytics->employeeFeatureUsage($this->window, null, 'tidak-ada-nama-ini');
        $this->assertSame(0, $none['total']);
        $this->assertSame([], $none['rows']);
        $this->assertSame(1, $none['last_page'], 'hasil kosong tetap punya satu halaman, bukan nol');
    }

    public function test_employee_feature_usage_respects_the_business_unit_scope(): void
    {
        $this->user('000001', ['group_company' => 'Downstream']);
        $this->user('000002', ['group_company' => 'Plantations']);

        $this->event(['employee_id' => '000001', 'business_unit' => 'Downstream']);
        $this->event(['employee_id' => '000002', 'business_unit' => 'Plantations']);

        $all = $this->analytics->employeeFeatureUsage($this->window);
        $this->assertSame(2, $all['total']);

        $scoped = $this->analytics->employeeFeatureUsage($this->window, null, null, 25, 1, ['Plantations']);
        $this->assertSame(1, $scoped['total']);
        $this->assertSame('000002', $scoped['rows'][0]['employee_id']);

        $nothing = $this->analytics->employeeFeatureUsage($this->window, null, null, 25, 1, ['-tanpa-akses-']);
        $this->assertSame(0, $nothing['total'], 'scope yang tidak cocok apa pun harus mengembalikan tabel kosong');
    }

    // ------------------------------------------------------------------
    // (e) riwayat Ruang
    // ------------------------------------------------------------------

    public function test_ruang_history_counts_only_a_failed_status_as_an_error(): void
    {
        // Nilai yang benar-benar ditulis RuangService: ok, ai_error, send_error.
        $rows = [
            ['id' => 1, 'status' => 'ok'],
            ['id' => 2, 'status' => 'ok'],
            ['id' => 3, 'status' => 'ai_error'],
        ];

        foreach ($rows as $i => $row) {
            DB::table('daily_chat_logs')->insert([
                'employee_id'   => '00000' . $row['id'],
                'employee_name' => 'Karyawan ' . $row['id'],
                'business_unit' => 'Downstream',
                'phone'         => '62811100000' . $row['id'],
                'date'          => '2026-09-0' . ($i + 1),
                'messages'      => json_encode([
                    ['sender' => 'User', 'message' => 'Pertanyaan ' . $row['id'], 'timestamp' => '2026-09-01 10:00:00'],
                    ['sender' => 'Nastari', 'message' => 'Jawaban ' . $row['id'], 'timestamp' => '2026-09-01 10:00:05'],
                ]),
                'last_status'   => $row['status'],
                'error_count'   => $row['status'] === 'ok' ? 0 : 1,
            ]);
        }

        $page = $this->analytics->ruangConversations($this->window);

        $this->assertSame(3, $page['total']);
        $this->assertSame(1, $page['errors'], 'hanya status selain ok yang dihitung bermasalah');

        $newest = $page['rows'][0];
        $this->assertSame('Pertanyaan 3', $newest['question']);
        $this->assertSame('Jawaban 3', $newest['answer']);
        $this->assertSame(1, $newest['exchanges']);
        $this->assertSame('6281****0003', $newest['phone_masked']);
    }

    public function test_ruang_history_pagination_clamps_and_filters_by_status(): void
    {
        foreach (range(1, 6) as $i) {
            DB::table('daily_chat_logs')->insert([
                'employee_id'   => '00000' . $i,
                'employee_name' => 'Karyawan ' . $i,
                'business_unit' => 'Downstream',
                'phone'         => '62811100000' . $i,
                'date'          => '2026-09-0' . $i,
                'messages'      => json_encode([['sender' => 'User', 'message' => 'Halo']]),
                'last_status'   => $i === 6 ? 'ai_error' : 'ok',
                'error_count'   => $i === 6 ? 2 : 0,
            ]);
        }

        $page2 = $this->analytics->ruangConversations($this->window, null, null, 5, 2);
        $this->assertSame(2, $page2['page']);
        $this->assertCount(1, $page2['rows']);

        $clamped = $this->analytics->ruangConversations($this->window, null, null, 5, 99);
        $this->assertSame(2, $clamped['page']);
        $this->assertCount(1, $clamped['rows']);

        $errorsOnly = $this->analytics->ruangConversations($this->window, null, 'error', 5, 1);
        $this->assertSame(1, $errorsOnly['total']);
        $this->assertSame('ai_error', $errorsOnly['rows'][0]['status']);
    }
}
