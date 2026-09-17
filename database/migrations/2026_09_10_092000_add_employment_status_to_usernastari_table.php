<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the active/inactive business rule and the registration distinction to
 * usernastari.
 *
 * `employment_status` mirrors the rule from kpncorp.employees:
 *   deleted_at IS NULL     -> active
 *   deleted_at IS NOT NULL -> inactive
 *
 * It defaults to 'unknown' rather than 'active' so that the 830 existing rows
 * are not *claimed* to be active before the first sync has actually checked
 * them. The access policy treats 'unknown' as allowed (fail-open): a sync
 * outage must never lock a legitimate employee out of the bot, and every
 * refusal is recorded so a false positive is visible the same day.
 *
 * `registration_status` exists because the daily sync pre-registers active
 * employees who have a usable phone number (~4.100 rows) alongside the ~830
 * who have actually talked to the bot. Without the distinction, every existing
 * "total registered users" metric would jump by 5x and stop meaning anything.
 * The bot resolves identity from 'registered' rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usernastari', function (Blueprint $table) {
            $table->string('employment_status', 12)->default('unknown')->after('whatsapp_number');
            $table->string('registration_status', 16)->default('registered')->after('employment_status');
            $table->timestamp('employee_status_synced_at')->nullable()->after('registration_status');
            $table->timestamp('employee_deleted_at')->nullable()->after('employee_status_synced_at');
        });

        Schema::table('usernastari', function (Blueprint $table) {
            $table->index('employment_status');
            $table->index('registration_status');

            // The dashboard's headline breakdown is BU x status.
            $table->index(['group_company', 'employment_status']);

            // The bot's identity lookup is phone + registration_status.
            $table->index(['whatsapp_number', 'registration_status'], 'usernastari_phone_regstatus_idx');
        });
    }

    public function down(): void
    {
        Schema::table('usernastari', function (Blueprint $table) {
            $table->dropIndex('usernastari_phone_regstatus_idx');
            $table->dropIndex(['group_company', 'employment_status']);
            $table->dropIndex(['registration_status']);
            $table->dropIndex(['employment_status']);
        });

        Schema::table('usernastari', function (Blueprint $table) {
            $table->dropColumn([
                'employment_status',
                'registration_status',
                'employee_status_synced_at',
                'employee_deleted_at',
            ]);
        });
    }
};
