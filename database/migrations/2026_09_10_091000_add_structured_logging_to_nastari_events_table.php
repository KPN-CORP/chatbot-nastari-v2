<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns nastari_events from a log-parsing product into a real event store that
 * the application writes to directly.
 *
 * Design constraint: NastariAnalyticsService and the eight analytics tabs
 * already query this table on `source`, `event_type`, `feature_key` and
 * `business_unit`. Live events therefore keep using that existing vocabulary so
 * every current metric keeps working unchanged, and the richer taxonomy the
 * dashboard needs is carried additively in `action_key`
 * (e.g. "employee.access.leave_balance", "external_service.timeout").
 *
 * `origin` is what stops double counting: the log ingestor keeps back-filling
 * history, but it must not re-import days the application already recorded
 * itself, or every number would be counted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nastari_events', function (Blueprint $table) {
            // 'ingest' = parsed out of storage/logs, 'live' = written in-request.
            $table->string('origin', 10)->default('ingest')->after('event_dow');

            // The requested event taxonomy, e.g. employee.access.leave_balance.
            $table->string('action_key', 60)->nullable()->after('event_type');

            $table->string('employee_status', 12)->nullable()->after('business_unit');
            $table->char('correlation_id', 36)->nullable()->after('is_known_employee');

            // External service attribution. endpoint stores "METHOD /path" only:
            // Darwinbox query strings can carry api_key values.
            $table->string('service', 40)->nullable()->after('outcome');
            $table->string('endpoint', 160)->nullable()->after('service');
            $table->string('error_type', 40)->nullable()->after('endpoint');
            $table->unsignedSmallInteger('http_status')->nullable()->after('error_type');
        });

        Schema::table('nastari_events', function (Blueprint $table) {
            $table->index('origin');
            $table->index('correlation_id');
            $table->index(['action_key', 'event_date']);
            $table->index(['service', 'event_date']);
            $table->index(['error_type', 'event_date']);
            $table->index(['employee_status', 'event_date']);

            // "Jam sibuk" groups by hour inside a date range.
            $table->index(['event_date', 'event_hour']);
        });
    }

    public function down(): void
    {
        Schema::table('nastari_events', function (Blueprint $table) {
            $table->dropIndex(['origin']);
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['action_key', 'event_date']);
            $table->dropIndex(['service', 'event_date']);
            $table->dropIndex(['error_type', 'event_date']);
            $table->dropIndex(['employee_status', 'event_date']);
            $table->dropIndex(['event_date', 'event_hour']);
        });

        Schema::table('nastari_events', function (Blueprint $table) {
            $table->dropColumn([
                'origin',
                'action_key',
                'employee_status',
                'correlation_id',
                'service',
                'endpoint',
                'error_type',
                'http_status',
            ]);
        });
    }
};
