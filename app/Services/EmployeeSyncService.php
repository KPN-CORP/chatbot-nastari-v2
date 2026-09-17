<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Daily reconciliation of usernastari against kpncorp.employees.
 *
 * Business rule it enforces:
 *   employees.deleted_at IS NULL     -> employment_status = 'active'
 *   employees.deleted_at IS NOT NULL -> employment_status = 'inactive'
 *   no matching employees row        -> employment_status = 'unknown'
 *
 * Safety rules, all of which come from measured data quality in kpncorp:
 *
 *   1. A field is only written when the source value is non-empty.
 *      `employees.date_of_joining` is 0% populated and
 *      `direct_reportees_employee_id` only 14%. A naive mirror would erase the
 *      joining date printed on every letter and strip the manager menu from
 *      86% of managers.
 *
 *   2. `whatsapp_number` is never overwritten once set. It is the bot's only
 *      identity key, `employees.whatsapp_number` is just 5% populated, and
 *      `personal_mobile_number` is dirty (values arrive as "'+62081385562449").
 *      Overwriting it would lock users out of the bot entirely.
 *
 *   3. A phone number shared by more than one active employee is never used to
 *      pre-register anyone. Three such numbers exist. Inserting them would let
 *      the bot answer one employee with another employee's HR data.
 *
 * Idempotent: running it twice changes nothing the second time.
 */
class EmployeeSyncService
{
    /** Hours after a successful run during which another run is skipped. */
    private const COOLDOWN_HOURS = 20;

    /**
     * Columns refreshed on existing rows, as usernastari => employees.
     *
     * Three obvious-looking candidates are deliberately absent, because an
     * audit of the real values showed kpncorp is the *worse* source for them:
     *
     *   unit_name         employees.unit appends an internal code, so all 829
     *                     rows would change "HC Information System" into
     *                     "HC Information System (CRPHC_ISD)" and leak that
     *                     code into what the bot shows the employee.
     *   company_email_id  30 rows carry a terminated-account marker, e.g.
     *                     "someone@kpn-corp.com_terminate", which is not a
     *                     deliverable address and would end up on letters.
     *   full_name         employees.fullname is dirtier: "Djuaman Lie" becomes
     *                     "Djuaman", "Susilo Sudarman" gains a double space.
     *
     * contribution_level IS synced, and matters more than it looks: it selects
     * the letterhead file, and kpncorp's spelling ("PT Jatim Jaya Perkasa") is
     * the one that matches the image on disk, unlike the stored spelling.
     */
    private const REFRESHABLE = [
        'group_company'                => 'group_company',
        'contribution_level'           => 'company_name',
        'designation_name'             => 'designation_name',
        'job_level'                    => 'job_level',
        'office_area'                  => 'office_area',
        'direct_manager_id'            => 'manager_l1_id',
        'l2_manager_id'                => 'manager_l2_id',
        'employee_type'                => 'employee_type',
        'place_of_birth'               => 'place_of_birth',
        'date_of_birth'                => 'date_of_birth',
        'direct_reportees_employee_id' => 'direct_reportees_employee_id',
        'date_of_joining'              => 'date_of_joining',
    ];

    /**
     * Additionally populated when creating a pre-registered row, where kpncorp
     * is the only source available. full_name is NOT NULL in the schema.
     */
    private const INSERT_ONLY = [
        'full_name' => 'fullname',
        'unit_name' => 'unit',
    ];

    /** Manager id column => the name column kept consistent with it. */
    private const MANAGER_NAMES = [
        'direct_manager_id' => 'direct_manager_name',
        'l2_manager_id'     => 'l2_manager_name',
    ];

    public function __construct(private DarwinboxService $darwin)
    {
    }

    /**
     * @return array<string, mixed> Counters, plus 'status' and 'message'.
     */
    public function sync(string $trigger = 'schedule', bool $force = false, bool $dryRun = false): array
    {
        if (! $force && ! $dryRun) {
            $recent = $this->recentSuccessfulRun();

            if ($recent !== null) {
                return [
                    'status'  => 'skipped',
                    'message' => 'A successful sync ran at ' . $recent . '; within the ' . self::COOLDOWN_HOURS . 'h cooldown.',
                ];
            }
        }

        $runId = $dryRun ? null : DB::table('employee_sync_runs')->insertGetId([
            'started_at' => now(),
            'trigger'    => $trigger,
            'status'     => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counters = [
            'examined'          => 0,
            'updated'           => 0,
            'marked_active'     => 0,
            'marked_inactive'   => 0,
            'pre_registered'    => 0,
            'not_found_in_hris' => 0,
            'ambiguous_phones'  => 0,
        ];

        try {
            $existing = $this->existingRows();
            $ambiguous = $this->ambiguousPhones();
            $counters['ambiguous_phones'] = count($ambiguous);

            // Manager names are stored alongside manager ids, but employees
            // only carries the ids. Syncing an id without its name would leave
            // 75 rows showing the previous manager's name against the new
            // manager's id.
            $names = $this->employeeNames();

            $statusBuckets = ['active' => [], 'inactive' => []];
            $fieldUpdates = [];
            $inserts = [];
            $seen = [];
            $takenPhones = $this->takenPhones();

            DB::connection('kpncorp')->table('employees')
                ->select(array_merge(
                    ['employee_id', 'deleted_at', 'personal_mobile_number', 'whatsapp_number'],
                    array_values(self::REFRESHABLE),
                    array_values(self::INSERT_ONLY)
                ))
                ->orderBy('id')
                ->chunk(500, function ($employees) use (
                    &$counters, &$statusBuckets, &$fieldUpdates, &$inserts, &$seen,
                    $existing, $ambiguous, &$takenPhones, $names
                ) {
                    foreach ($employees as $employee) {
                        $employeeId = (string) $employee->employee_id;

                        if ($employeeId === '') {
                            continue;
                        }

                        $counters['examined']++;
                        $seen[$employeeId] = true;

                        $isActive = $employee->deleted_at === null;
                        $status = $isActive ? 'active' : 'inactive';

                        if (! isset($existing[$employeeId])) {
                            // Not registered. Pre-register only active employees
                            // with a clean, unshared, unclaimed phone number.
                            if (! $isActive) {
                                continue;
                            }

                            // Real NIKs are numeric. employees also holds a
                            // service account ("DBOX", Darwinbox Admin) that
                            // has a phone number and would otherwise be
                            // pre-registered as a bot user.
                            if (preg_match('/^[0-9]{6,}$/', $employeeId) !== 1) {
                                continue;
                            }

                            $phone = $this->usablePhone($employee);

                            if ($phone === null || isset($ambiguous[$phone]) || isset($takenPhones[$phone])) {
                                continue;
                            }

                            $takenPhones[$phone] = $employeeId;
                            $inserts[] = $this->insertRow($employee, $employeeId, $phone, $names);
                            $counters['pre_registered']++;

                            continue;
                        }

                        $current = $existing[$employeeId];

                        if ($current->employment_status !== $status) {
                            $counters[$isActive ? 'marked_active' : 'marked_inactive']++;
                        }

                        $statusBuckets[$status][] = $employeeId;

                        $changes = $this->fieldChanges($current, $employee, $names);

                        if ($changes !== []) {
                            $fieldUpdates[$employeeId] = $changes;
                        }
                    }
                });

            // Registered rows with no employees row at all stay usable, but are
            // marked 'unknown' so the access policy fails open rather than
            // refusing someone because of a missing HRIS record.
            $orphans = [];

            foreach (array_keys($existing) as $employeeId) {
                if (! isset($seen[$employeeId])) {
                    $orphans[] = $employeeId;
                }
            }

            $counters['not_found_in_hris'] = count($orphans);

            if (! $dryRun) {
                $counters['updated'] = $this->apply($statusBuckets, $fieldUpdates, $inserts, $orphans);
            } else {
                $counters['updated'] = count($fieldUpdates);
            }

            if ($runId !== null) {
                DB::table('employee_sync_runs')->where('id', $runId)->update(array_merge($counters, [
                    'finished_at' => now(),
                    'status'      => 'ok',
                    'updated_at'  => now(),
                ]));
            }

            return array_merge($counters, ['status' => $dryRun ? 'dry-run' : 'ok', 'message' => null]);
        } catch (\Throwable $e) {
            Log::error('EMPLOYEE_SYNC_FAILED', ['error' => $e->getMessage()]);

            if ($runId !== null) {
                DB::table('employee_sync_runs')->where('id', $runId)->update([
                    'finished_at' => now(),
                    'status'      => 'failed',
                    'message'     => Str::limit($e->getMessage(), 500),
                    'updated_at'  => now(),
                ]);
            }

            return array_merge($counters, ['status' => 'failed', 'message' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------

    private function recentSuccessfulRun(): ?string
    {
        $run = DB::table('employee_sync_runs')
            ->where('status', 'ok')
            ->where('started_at', '>=', now()->subHours(self::COOLDOWN_HOURS))
            ->latest('started_at')
            ->first();

        return $run === null ? null : (string) $run->started_at;
    }

    /** @return array<string, object> usernastari rows keyed by employee_id. */
    private function existingRows(): array
    {
        $rows = [];

        DB::table('usernastari')
            ->select(array_merge(
                ['id', 'employee_id', 'whatsapp_number', 'employment_status', 'registration_status'],
                array_keys(self::REFRESHABLE),
                array_values(self::MANAGER_NAMES)
            ))
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$rows) {
                foreach ($chunk as $row) {
                    $rows[(string) $row->employee_id] = $row;
                }
            });

        return $rows;
    }

    /**
     * employee_id => fullname for the whole master, used to keep manager names
     * consistent with manager ids.
     *
     * @return array<string, string>
     */
    private function employeeNames(): array
    {
        $names = [];

        DB::connection('kpncorp')->table('employees')
            ->select(['id', 'employee_id', 'fullname'])
            ->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$names) {
                foreach ($chunk as $row) {
                    $name = trim((string) $row->fullname);

                    if ($name !== '') {
                        $names[(string) $row->employee_id] = $name;
                    }
                }
            });

        return $names;
    }

    /**
     * Cleaned phone numbers claimed by more than one active employee.
     *
     * @return array<string, int>
     */
    private function ambiguousPhones(): array
    {
        $counts = [];

        DB::connection('kpncorp')->table('employees')
            ->whereNull('deleted_at')
            ->select(['id', 'personal_mobile_number', 'whatsapp_number'])
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$counts) {
                foreach ($chunk as $row) {
                    $phone = $this->usablePhone($row);

                    if ($phone !== null) {
                        $counts[$phone] = ($counts[$phone] ?? 0) + 1;
                    }
                }
            });

        return array_filter($counts, fn ($count) => $count > 1);
    }

    /** @return array<string, string> phone => employee_id already in usernastari. */
    private function takenPhones(): array
    {
        $taken = [];

        DB::table('usernastari')
            ->whereNotNull('whatsapp_number')
            ->where('whatsapp_number', '!=', '')
            ->select(['id', 'employee_id', 'whatsapp_number'])
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$taken) {
                foreach ($chunk as $row) {
                    $taken[(string) $row->whatsapp_number] = (string) $row->employee_id;
                }
            });

        return $taken;
    }

    /**
     * A phone number good enough to identify someone by.
     *
     * Values in kpncorp arrive as "'+6281320632632" and occasionally
     * "'+62081385562449"; cleanMobileNumber() normalises both. Anything that
     * does not end up as a plausible Indonesian mobile is rejected rather than
     * guessed at.
     */
    private function usablePhone(object $employee): ?string
    {
        foreach (['whatsapp_number', 'personal_mobile_number'] as $field) {
            $raw = trim((string) ($employee->{$field} ?? ''));

            if ($raw === '') {
                continue;
            }

            $clean = $this->darwin->cleanMobileNumber($raw);

            if (preg_match('/^62[0-9]{8,13}$/', $clean) === 1) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * Non-empty source values that differ from what is stored.
     *
     * @return array<string, mixed>
     */
    private function fieldChanges(object $current, object $employee, array $names): array
    {
        $changes = [];

        foreach (self::REFRESHABLE as $target => $source) {
            $value = $employee->{$source} ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            // Rule 1: never write an empty value over stored data.
            if ($value === null || $value === '' || $value === '0000-00-00') {
                continue;
            }

            $currentValue = $current->{$target} ?? null;

            if (is_string($currentValue)) {
                $currentValue = trim($currentValue);
            }

            if ((string) $currentValue !== (string) $value) {
                $changes[$target] = $value;
            }
        }

        // Keep each manager name in step with the id it describes, but only
        // when the id itself changed. The stored names carry the NIK in the
        // Darwinbox format "Budi Setiono (01122100006)"; rewriting them from
        // employees.fullname alone would restyle 709 rows and throw the NIK
        // away without correcting anything.
        foreach (self::MANAGER_NAMES as $idColumn => $nameColumn) {
            if (! isset($changes[$idColumn])) {
                continue;
            }

            $newId = (string) $changes[$idColumn];
            $resolved = $names[$newId] ?? null;

            if ($resolved === null) {
                continue;
            }

            $changes[$nameColumn] = $resolved . ' (' . $newId . ')';
        }

        return $changes;
    }

    /**
     * Every column that a pre-registered row can carry, defaulted to null.
     *
     * A batch insert takes its column list from the first row, so rows built
     * with different key sets fail with "Column count doesn't match value
     * count at row 2". Starting from a fixed template guarantees every row in
     * the batch has the same columns in the same order.
     *
     * @return array<string, mixed>
     */
    private function insertTemplate(): array
    {
        $template = [
            'employee_id'               => null,
            'whatsapp_number'           => null,
            'employment_status'         => 'active',
            'registration_status'       => 'pre_registered',
            'employee_status_synced_at' => null,
            'employee_deleted_at'       => null,
            'created_at'                => null,
            'updated_at'                => null,
        ];

        foreach (array_keys(self::REFRESHABLE) as $column) {
            $template[$column] = null;
        }

        foreach (array_keys(self::INSERT_ONLY) as $column) {
            $template[$column] = null;
        }

        foreach (self::MANAGER_NAMES as $column) {
            $template[$column] = null;
        }

        return $template;
    }

    /** @return array<string, mixed> */
    private function insertRow(object $employee, string $employeeId, string $phone, array $names): array
    {
        $row = array_merge($this->insertTemplate(), [
            'employee_id'               => $employeeId,
            'whatsapp_number'           => $phone,
            'employment_status'         => 'active',
            'registration_status'       => 'pre_registered',
            'employee_status_synced_at' => now(),
            'employee_deleted_at'       => null,
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        foreach (array_merge(self::REFRESHABLE, self::INSERT_ONLY) as $target => $source) {
            $value = $employee->{$source} ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '' || $value === '0000-00-00') {
                continue;
            }

            $row[$target] = $value;
        }

        foreach (self::MANAGER_NAMES as $idColumn => $nameColumn) {
            $managerId = (string) ($row[$idColumn] ?? '');
            $resolved = $names[$managerId] ?? null;

            if ($resolved !== null) {
                // Same "Name (NIK)" shape the Darwinbox sync produces.
                $row[$nameColumn] = $resolved . ' (' . $managerId . ')';
            }
        }

        // full_name is NOT NULL in the schema.
        if (empty($row['full_name'])) {
            $row['full_name'] = 'Karyawan ' . $employeeId;
        }

        return $row;
    }

    /**
     * Writes everything in as few statements as the shape allows.
     *
     * Status is set with a handful of whereIn updates rather than one statement
     * per employee: the corporate database is remote, so 6.300 round trips
     * would dominate the runtime of the whole job.
     */
    private function apply(array $statusBuckets, array $fieldUpdates, array $inserts, array $orphans): int
    {
        // Not wrapped in a transaction on purpose. This touches thousands of
        // rows on a remote database, and holding one transaction open for the
        // whole job risks lock contention with the live bot. The job is
        // idempotent, so a partial application simply completes on the next
        // run; the status writes come first because the access policy depends
        // on them.
        $updated = 0;

        foreach ($statusBuckets as $status => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('usernastari')
                    ->whereIn('employee_id', $chunk)
                    ->update([
                        'employment_status'         => $status,
                        'employee_status_synced_at' => now(),
                        'updated_at'                => now(),
                    ]);
            }
        }

        // employee_deleted_at is only meaningful for inactive rows; clearing it
        // for active rows keeps the two columns from contradicting each other.
        foreach (array_chunk($statusBuckets['active'] ?? [], 1000) as $chunk) {
            DB::table('usernastari')->whereIn('employee_id', $chunk)
                ->whereNotNull('employee_deleted_at')
                ->update(['employee_deleted_at' => null]);
        }

        foreach (array_chunk($orphans, 1000) as $chunk) {
            DB::table('usernastari')
                ->whereIn('employee_id', $chunk)
                ->update([
                    'employment_status'         => 'unknown',
                    'employee_status_synced_at' => now(),
                    'updated_at'                => now(),
                ]);
        }

        foreach ($fieldUpdates as $employeeId => $changes) {
            $changes['updated_at'] = now();

            DB::table('usernastari')->where('employee_id', $employeeId)->update($changes);
            $updated++;
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            // insertOrIgnore rather than insert: employee_id is unique, and a
            // concurrent first-contact registration must not fail the sync.
            DB::table('usernastari')->insertOrIgnore($chunk);
        }

        return $updated;
    }

    /**
     * Marks the inactive employees' deletion timestamps in a second pass.
     *
     * Kept separate from apply() so a failure here cannot roll back the status
     * write, which is the part the access policy depends on.
     */
    public function backfillDeletionTimestamps(int $limit = 5000): int
    {
        $touched = 0;

        DB::connection('kpncorp')->table('employees')
            ->whereNotNull('deleted_at')
            ->select(['id', 'employee_id', 'deleted_at'])
            ->orderBy('id')
            ->limit($limit)
            ->chunk(500, function ($chunk) use (&$touched) {
                foreach ($chunk as $row) {
                    $affected = DB::table('usernastari')
                        ->where('employee_id', (string) $row->employee_id)
                        ->whereNull('employee_deleted_at')
                        ->update(['employee_deleted_at' => $row->deleted_at]);

                    $touched += $affected;
                }
            });

        return $touched;
    }
}
