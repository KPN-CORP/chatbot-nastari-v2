<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the daily employee sync, mirroring nastari_ingest_runs.
 *
 * It is also the idempotency guard: a run that starts within the cooldown of a
 * successful run exits as 'skipped'. Production may end up with both a cron
 * entry and an external HTTP trigger, and the sync must not run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();

            $table->string('trigger', 20)->default('schedule'); // schedule|manual|http

            $table->unsignedInteger('examined')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('marked_active')->default(0);
            $table->unsignedInteger('marked_inactive')->default(0);
            $table->unsignedInteger('pre_registered')->default(0);
            $table->unsignedInteger('not_found_in_hris')->default(0);
            $table->unsignedInteger('ambiguous_phones')->default(0);

            $table->string('status', 20)->default('running'); // running|ok|failed|skipped
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sync_runs');
    }
};
