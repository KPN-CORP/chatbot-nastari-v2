<?php

namespace Tests\Feature;

use App\Services\DarwinboxService;
use App\Services\EmployeeSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * The daily reconciliation of usernastari against kpncorp.employees.
 *
 * Both connections are pointed at one temporary SQLite file so the real
 * cross-database query path is exercised (an in-memory database per connection
 * would not be shared).
 *
 * The rules under test are the ones that would cause real damage if broken:
 * status mapping, idempotency, and never destroying good data with empty or
 * dirtier values from the HRIS master.
 */
class EmployeeSyncTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nastari_sync_' . uniqid('', false) . '.sqlite';
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

        $this->createEmployeesTable();
    }

    protected function tearDown(): void
    {
        DB::purge('nastari_test');
        DB::purge('kpncorp');
        @unlink($this->dbPath);

        parent::tearDown();
    }

    /** kpncorp has no migrations by design, so the test creates what it reads. */
    private function createEmployeesTable(): void
    {
        Schema::connection('kpncorp')->create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->nullable();
            $table->string('fullname')->nullable();
            $table->string('email')->nullable();
            $table->string('group_company')->nullable();
            $table->string('company_name')->nullable();
            $table->string('unit')->nullable();
            $table->string('designation_name')->nullable();
            $table->string('job_level')->nullable();
            $table->string('office_area')->nullable();
            $table->string('employee_type')->nullable();
            $table->string('manager_l1_id')->nullable();
            $table->string('manager_l2_id')->nullable();
            $table->text('direct_reportees_employee_id')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('date_of_birth')->nullable();
            $table->string('date_of_joining')->nullable();
            $table->string('personal_mobile_number')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function employee(array $attributes = []): void
    {
        DB::connection('kpncorp')->table('employees')->insert(array_merge([
            'employee_id'            => '01123070001',
            'fullname'               => 'Budi Santoso',
            'email'                  => 'budi@kpn-corp.com',
            'group_company'          => 'Downstream',
            'company_name'           => 'PT Energi Unggul Persada',
            'unit'                   => 'Finance (DWS_FIN)',
            'designation_name'       => 'Staff',
            'job_level'              => '5A',
            'office_area'            => 'Corporate Gama Tower',
            'employee_type'          => 'Permanent',
            'manager_l1_id'          => '01120040011',
            'manager_l2_id'          => null,
            'personal_mobile_number' => "'+6281200000001",
            'deleted_at'             => null,
        ], $attributes));
    }

    private function registered(array $attributes = []): void
    {
        DB::table('usernastari')->insert(array_merge([
            'employee_id'         => '01123070001',
            'whatsapp_number'     => '6281200000001',
            'full_name'           => 'Budi Santoso',
            'employment_status'   => 'unknown',
            'registration_status' => 'registered',
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $attributes));
    }

    private function sync(): array
    {
        return app(EmployeeSyncService::class)->sync('manual', true, false);
    }

    private function row(string $employeeId): ?object
    {
        return DB::table('usernastari')->where('employee_id', $employeeId)->first();
    }

    // ------------------------------------------------------------------
    // The business rule
    // ------------------------------------------------------------------

    public function test_soft_deleted_employee_becomes_inactive_and_live_one_becomes_active(): void
    {
        $this->employee(['employee_id' => '01123070001', 'deleted_at' => null]);
        $this->employee(['employee_id' => '01123070002', 'deleted_at' => '2026-06-30 00:00:00', 'personal_mobile_number' => "'+6281200000002"]);

        $this->registered(['employee_id' => '01123070001', 'whatsapp_number' => '6281200000001']);
        $this->registered(['employee_id' => '01123070002', 'whatsapp_number' => '6281200000002']);

        $result = $this->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('active', $this->row('01123070001')->employment_status);
        $this->assertSame('inactive', $this->row('01123070002')->employment_status);
    }

    public function test_a_registered_row_missing_from_hris_becomes_unknown_not_inactive(): void
    {
        // Fails open: no employees row is a data gap, not a proven leaver.
        $this->registered(['employee_id' => '09999999999', 'whatsapp_number' => '6281299999999']);

        $result = $this->sync();

        $this->assertSame(1, $result['not_found_in_hris']);
        $this->assertSame('unknown', $this->row('09999999999')->employment_status);
    }

    public function test_status_flips_back_when_an_employee_is_reinstated(): void
    {
        $this->employee(['deleted_at' => '2026-06-30 00:00:00']);
        $this->registered();

        $this->sync();
        $this->assertSame('inactive', $this->row('01123070001')->employment_status);

        DB::connection('kpncorp')->table('employees')
            ->where('employee_id', '01123070001')->update(['deleted_at' => null]);

        $this->sync();

        $row = $this->row('01123070001');
        $this->assertSame('active', $row->employment_status);
        $this->assertNull($row->employee_deleted_at, 'A reinstated employee must not keep a deletion timestamp');
    }

    // ------------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------------

    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $this->employee();
        $this->employee(['employee_id' => '01123070002', 'personal_mobile_number' => "'+6281200000002"]);
        $this->registered();

        $first = $this->sync();
        $second = $this->sync();

        $this->assertSame(1, $first['pre_registered']);
        $this->assertSame(0, $second['pre_registered'], 'Pre-registration must not repeat');
        $this->assertSame(0, $second['updated'], 'Nothing should be updated on an unchanged second run');
        $this->assertSame(0, $second['marked_active']);
        $this->assertSame(0, $second['marked_inactive']);

        $this->assertSame(2, DB::table('usernastari')->count(), 'No duplicate rows');
        $this->assertSame(
            1,
            DB::table('usernastari')->where('employee_id', '01123070001')->count(),
            'employee_id must stay unique'
        );
    }

    // ------------------------------------------------------------------
    // Never destroy good data
    // ------------------------------------------------------------------

    public function test_an_empty_source_value_never_overwrites_stored_data(): void
    {
        // employees.date_of_joining is 0% populated in production; overwriting
        // would erase the joining date printed on every letter.
        $this->employee(['date_of_joining' => null, 'place_of_birth' => '', 'direct_reportees_employee_id' => null]);
        $this->registered([
            'date_of_joining'              => '2020-01-15',
            'place_of_birth'               => 'Jakarta',
            'direct_reportees_employee_id' => 'DBOX|01120040011',
        ]);

        $this->sync();
        $row = $this->row('01123070001');

        $this->assertSame('2020-01-15', (string) $row->date_of_joining);
        $this->assertSame('Jakarta', $row->place_of_birth);
        $this->assertSame('DBOX|01120040011', $row->direct_reportees_employee_id);
    }

    public function test_the_whatsapp_number_is_never_overwritten_for_a_registered_user(): void
    {
        // The phone is the bot's only identity key, and the HRIS copy is dirty.
        $this->employee(['personal_mobile_number' => "'+6289999999999", 'whatsapp_number' => "'+6288888888888"]);
        $this->registered(['whatsapp_number' => '6281200000001']);

        $this->sync();

        $this->assertSame('6281200000001', $this->row('01123070001')->whatsapp_number);
    }

    public function test_name_email_and_unit_are_left_alone_because_hris_is_the_worse_source(): void
    {
        $this->employee([
            'fullname' => 'Budi',                              // truncated in HRIS
            'email'    => 'budi@kpn-corp.com_terminate',       // not deliverable
            'unit'     => 'Finance (DWS_FIN)',                 // carries an internal code
        ]);
        $this->registered([
            'full_name'        => 'Budi Santoso',
            'company_email_id' => 'budi@kpn-corp.com',
            'unit_name'        => 'Finance',
        ]);

        $this->sync();
        $row = $this->row('01123070001');

        $this->assertSame('Budi Santoso', $row->full_name);
        $this->assertSame('budi@kpn-corp.com', $row->company_email_id);
        $this->assertSame('Finance', $row->unit_name);
    }

    public function test_genuine_organisational_changes_are_applied(): void
    {
        $this->employee([
            'job_level'        => '6A',
            'designation_name' => 'Section Head',
            'company_name'     => 'PT Jatim Jaya Perkasa',
        ]);
        $this->registered([
            'job_level'          => '5A',
            'designation_name'   => 'Staff',
            'contribution_level' => 'PT Jatimjaya Perkasa',
        ]);

        $result = $this->sync();
        $row = $this->row('01123070001');

        $this->assertSame(1, $result['updated']);
        $this->assertSame('6A', $row->job_level);
        $this->assertSame('Section Head', $row->designation_name);
        // contribution_level selects the letterhead file, so the HRIS spelling
        // is the one that must win.
        $this->assertSame('PT Jatim Jaya Perkasa', $row->contribution_level);
    }

    public function test_manager_name_follows_a_changed_manager_id_and_keeps_the_nik_format(): void
    {
        $this->employee(['manager_l1_id' => '01120040012']);
        DB::connection('kpncorp')->table('employees')->insert([
            'employee_id' => '01120040012',
            'fullname'    => 'Hendra Wijaya',
            'deleted_at'  => null,
        ]);

        $this->registered([
            'direct_manager_id'   => '01120040011',
            'direct_manager_name' => 'Rolles Herwin Sihombing (01120040011)',
        ]);

        $this->sync();
        $row = $this->row('01123070001');

        $this->assertSame('01120040012', $row->direct_manager_id);
        $this->assertSame('Hendra Wijaya (01120040012)', $row->direct_manager_name);
    }

    /**
     * The stored names are "Name (NIK)"; rewriting them from employees.fullname
     * alone would restyle 709 production rows and drop the NIK, correcting
     * nothing. So the name only moves when its id moves.
     */
    public function test_manager_name_is_untouched_when_the_manager_id_is_unchanged(): void
    {
        $this->employee(['manager_l1_id' => '01120040011']);
        DB::connection('kpncorp')->table('employees')->insert([
            'employee_id' => '01120040011',
            'fullname'    => 'Rolles Herwin Sihombing',
            'deleted_at'  => null,
        ]);

        $this->registered([
            'direct_manager_id'   => '01120040011',
            'direct_manager_name' => 'Rolles Herwin Sihombing (01120040011)',
        ]);

        // First run also settles the other fields this fixture leaves blank.
        $this->sync();

        $this->assertSame(
            'Rolles Herwin Sihombing (01120040011)',
            $this->row('01123070001')->direct_manager_name,
            'An unchanged manager must keep its stored "Name (NIK)" form'
        );

        // With everything settled, a further run must be a complete no-op —
        // proving the manager name is not rewritten on every pass.
        $second = $this->sync();

        $this->assertSame(0, $second['updated']);
        $this->assertSame(
            'Rolles Herwin Sihombing (01120040011)',
            $this->row('01123070001')->direct_manager_name
        );
    }

    // ------------------------------------------------------------------
    // Pre-registration safety
    // ------------------------------------------------------------------

    public function test_active_employee_with_a_clean_phone_is_pre_registered_not_registered(): void
    {
        $this->employee();

        $result = $this->sync();
        $row = $this->row('01123070001');

        $this->assertSame(1, $result['pre_registered']);
        $this->assertSame('pre_registered', $row->registration_status);
        $this->assertSame('active', $row->employment_status);
        // cleanMobileNumber must have normalised "'+6281200000001".
        $this->assertSame('6281200000001', $row->whatsapp_number);
    }

    public function test_inactive_employees_are_never_pre_registered(): void
    {
        $this->employee(['deleted_at' => '2026-01-01 00:00:00']);

        $result = $this->sync();

        $this->assertSame(0, $result['pre_registered']);
        $this->assertNull($this->row('01123070001'));
    }

    public function test_employees_without_a_usable_phone_are_not_pre_registered(): void
    {
        $this->employee(['employee_id' => '01123070001', 'personal_mobile_number' => null, 'whatsapp_number' => null]);
        $this->employee(['employee_id' => '01123070002', 'personal_mobile_number' => '-', 'whatsapp_number' => null]);
        $this->employee(['employee_id' => '01123070003', 'personal_mobile_number' => "'+62123", 'whatsapp_number' => null]);

        $result = $this->sync();

        $this->assertSame(0, $result['pre_registered']);
        $this->assertSame(0, DB::table('usernastari')->count());
    }

    /**
     * Three production numbers are shared by two active employees each.
     * Pre-registering them would let the bot answer one person with another
     * person's HR data.
     */
    public function test_a_phone_shared_by_two_active_employees_is_never_pre_registered(): void
    {
        $this->employee(['employee_id' => '01123070001', 'personal_mobile_number' => "'+6285555555555"]);
        $this->employee(['employee_id' => '01123070002', 'personal_mobile_number' => "'+6285555555555"]);

        $result = $this->sync();

        $this->assertSame(2, $result['ambiguous_phones'] > 0 ? 2 : 0);
        $this->assertSame(0, $result['pre_registered']);
        $this->assertSame(0, DB::table('usernastari')->count());
    }

    public function test_a_phone_already_claimed_by_a_registered_user_is_not_pre_registered_again(): void
    {
        $this->employee(['employee_id' => '01123070001', 'personal_mobile_number' => "'+6281200000001"]);
        $this->employee(['employee_id' => '01123070002', 'personal_mobile_number' => "'+6281200000001"]);
        // Only 01123070001 is registered; the number belongs to them.
        $this->registered(['employee_id' => '01123070001', 'whatsapp_number' => '6281200000001']);

        $this->sync();

        $this->assertNull($this->row('01123070002'));
        $this->assertSame(1, DB::table('usernastari')->count());
    }

    public function test_non_numeric_service_accounts_are_excluded(): void
    {
        // employees holds a "DBOX" / Darwinbox Admin service account that has a
        // phone number and would otherwise become a bot user.
        $this->employee(['employee_id' => 'DBOX', 'fullname' => 'Darwinbox Admin', 'personal_mobile_number' => "'+6287851202857"]);

        $result = $this->sync();

        $this->assertSame(0, $result['pre_registered']);
        $this->assertNull($this->row('DBOX'));
    }

    // ------------------------------------------------------------------
    // Operational guards
    // ------------------------------------------------------------------

    public function test_a_second_run_inside_the_cooldown_is_skipped(): void
    {
        $this->employee();
        $this->registered();

        app(EmployeeSyncService::class)->sync('schedule', false, false);
        $second = app(EmployeeSyncService::class)->sync('http', false, false);

        $this->assertSame('skipped', $second['status']);
        $this->assertStringContainsString('cooldown', $second['message']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->employee();
        $this->registered(['employment_status' => 'unknown']);

        $result = app(EmployeeSyncService::class)->sync('manual', true, true);

        $this->assertSame('dry-run', $result['status']);
        $this->assertSame('unknown', $this->row('01123070001')->employment_status);
        $this->assertSame(0, DB::table('employee_sync_runs')->count());
    }

    public function test_every_run_is_recorded_for_audit(): void
    {
        $this->employee();
        $this->sync();

        $run = DB::table('employee_sync_runs')->latest('id')->first();

        $this->assertSame('ok', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertNotNull($run->finished_at);
        $this->assertGreaterThan(0, $run->examined);
    }

    public function test_phone_normalisation_matches_the_bot_lookup(): void
    {
        // The bot stores and looks up the cleaned form, so the sync must agree
        // with cleanMobileNumber() exactly or pre-registered rows never match.
        $darwin = app(DarwinboxService::class);

        $this->assertSame('6281320632632', $darwin->cleanMobileNumber("'+6281320632632"));
        $this->assertSame('6282246336730', $darwin->cleanMobileNumber("'+62082246336730"));
        $this->assertSame('6281385562449', $darwin->cleanMobileNumber("'+62081385562449"));
        $this->assertSame('6281234567890', $darwin->cleanMobileNumber('081234567890'));
        $this->assertSame('6281234567890', $darwin->cleanMobileNumber('81234567890'));
    }
}
