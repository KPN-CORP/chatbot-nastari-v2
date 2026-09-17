<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('chat:analyze-weekly')->weeklyOn(6, '06:00');

// Analytics ingest: logs rotate after 14 days, nastari_events keeps the history.
// Since the application now writes its own events, this only back-fills days
// that predate live logging (see NastariEventIngestor::liveCutoverDate()).
Schedule::command('nastari:ingest-logs')->everyTenMinutes()->withoutOverlapping();

// Diagnostics, not metrics: ERROR-level entries from storage/logs are copied
// into nastari_log_entries so they survive the 14-day log rotation and are
// visible on the dashboard. Fatal PHP errors exist *only* here — the process
// dies before the activity logger can write anything. Reads only the bytes
// added since the previous run, so the cadence is cheap regardless of log size.
Schedule::command('nastari:scan-logs')->everyTenMinutes()->withoutOverlapping();

// Daily reconciliation of usernastari against kpncorp.employees: the
// active/inactive business rule plus HRIS pre-registration. Runs off-peak
// because it walks the whole remote employee master.
//
// Whether this scheduler is actually wired up in production is unconfirmed, so
// the same command is also reachable manually and over HTTP
// (GET /tasks/employee-sync). EmployeeSyncService enforces a 20h cooldown, so
// two triggers firing on the same day cannot double-apply anything.
Schedule::command('nastari:sync-employees --trigger=schedule')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// Generated letter PDFs are delivered over WhatsApp and then only take up
// disk. letter_logs keeps the audit trail either way.
Schedule::command('nastari:prune-letters')->weeklyOn(0, '03:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
