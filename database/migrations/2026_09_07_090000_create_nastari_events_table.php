<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable analytics event store for Nastari.
 *
 * Rows are ingested from storage/logs by `nastari:ingest-logs`. Logs rotate
 * every 14 days; this table does not, so it becomes the permanent history.
 * Nothing in the WhatsApp request path writes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nastari_events', function (Blueprint $table) {
            $table->id();

            $table->dateTime('occurred_at');
            $table->date('event_date');
            $table->unsignedTinyInteger('event_hour');
            $table->unsignedTinyInteger('event_dow'); // 1=Monday .. 7=Sunday

            // 'wa_incoming' | 'lookup' | 'pandu' | 'error'
            $table->string('source', 20);
            $table->string('event_type', 40);

            // Identity. Phone is stored cleaned; employee_id/business_unit are
            // enriched from usernastari at ingest time where a match exists.
            $table->string('phone', 25)->nullable();
            $table->string('employee_id', 25)->nullable();
            $table->string('business_unit', 60)->nullable();
            $table->boolean('is_known_employee')->default(false);

            // Feature attribution. feature_key is a slug so a menu item that
            // gets renumbered ("4. Ruang" -> "7. Ruang") stays one feature.
            $table->string('feature_key', 60)->nullable();
            $table->string('feature_label', 120)->nullable();

            $table->string('message_type', 20)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('payload')->nullable();

            // sha1 of the source log line — makes re-ingest idempotent.
            $table->char('fingerprint', 40)->unique();

            $table->timestamp('created_at')->nullable();

            $table->index('event_date');
            $table->index(['event_type', 'event_date']);
            $table->index(['business_unit', 'event_date']);
            $table->index(['feature_key', 'event_date']);
            $table->index(['source', 'event_date']);
            $table->index(['phone', 'event_date']);
            $table->index(['employee_id', 'event_date']);
        });

        Schema::create('nastari_ingest_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('lines_read')->default(0);
            $table->unsignedInteger('events_inserted')->default(0);
            $table->unsignedInteger('events_skipped')->default(0);
            $table->string('status', 20)->default('running');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nastari_ingest_runs');
        Schema::dropIfExists('nastari_events');
    }
};
