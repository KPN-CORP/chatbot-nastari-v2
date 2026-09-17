<?php

namespace App\Console\Commands;

use App\Services\EmployeeSyncService;
use Illuminate\Console\Command;

/**
 * The single entry point for the daily employee sync.
 *
 * Production infrastructure is unconfirmed, so the same logic is reachable
 * three ways — this command, the scheduler entry in routes/console.php, and the
 * token-protected /tasks/employee-sync route — and EmployeeSyncService's
 * cooldown makes it harmless if more than one of them fires.
 */
class SyncEmployees extends Command
{
    protected $signature = 'nastari:sync-employees
                            {--force : Ignore the cooldown since the last successful run}
                            {--dry-run : Report what would change without writing}
                            {--trigger=manual : Recorded in employee_sync_runs (schedule|manual|http)}';

    protected $description = 'Reconcile usernastari against kpncorp.employees (active/inactive + pre-registration)';

    public function handle(EmployeeSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Dry run: reconciling usernastari against kpncorp.employees…'
            : 'Reconciling usernastari against kpncorp.employees…');

        $started = microtime(true);

        $result = $sync->sync(
            (string) $this->option('trigger'),
            (bool) $this->option('force'),
            $dryRun
        );

        if ($result['status'] === 'skipped') {
            $this->warn($result['message']);
            $this->line('Use --force to run anyway.');

            return self::SUCCESS;
        }

        if ($result['status'] === 'failed') {
            $this->error('Sync failed: ' . $result['message']);

            return self::FAILURE;
        }

        $this->table(
            ['Examined', 'Fields updated', 'Marked active', 'Marked inactive', 'Pre-registered', 'Not in HRIS', 'Ambiguous phones', 'Seconds'],
            [[
                number_format($result['examined']),
                number_format($result['updated']),
                number_format($result['marked_active']),
                number_format($result['marked_inactive']),
                number_format($result['pre_registered']),
                number_format($result['not_found_in_hris']),
                number_format($result['ambiguous_phones']),
                round(microtime(true) - $started, 2),
            ]]
        );

        if (! $dryRun && $result['ambiguous_phones'] > 0) {
            $this->warn($result['ambiguous_phones'] . ' phone number(s) are shared by more than one active employee and were not pre-registered. They need fixing in Darwinbox.');
        }

        return self::SUCCESS;
    }
}
