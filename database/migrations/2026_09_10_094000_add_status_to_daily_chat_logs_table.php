<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the Ruang dashboard show whether a conversation actually worked.
 *
 * Ruang only ever persisted successful exchanges: when the Gemini call failed,
 * the employee got "koneksi Nastari terputus" and nothing was written at all,
 * so the failure was invisible to anyone reviewing the transcripts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_chat_logs', function (Blueprint $table) {
            $table->string('last_status', 20)->default('ok')->after('messages'); // ok|ai_error|send_error
            $table->unsignedSmallInteger('error_count')->default(0)->after('last_status');
        });

        Schema::table('daily_chat_logs', function (Blueprint $table) {
            $table->index('last_status');

            // The dashboard lists conversations newest-first, filtered by BU.
            $table->index(['date', 'business_unit']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_chat_logs', function (Blueprint $table) {
            $table->dropIndex(['date', 'business_unit']);
            $table->dropIndex(['last_status']);
        });

        Schema::table('daily_chat_logs', function (Blueprint $table) {
            $table->dropColumn(['last_status', 'error_count']);
        });
    }
};
