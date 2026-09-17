<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat tanya-jawab Pandu.
 *
 * Sebelum tabel ini, jawaban Pandu tidak pernah tersimpan di mana pun: hasil
 * dari layanan RAG dikirim ke WhatsApp lalu hilang. Yang tertinggal hanya
 * pertanyaannya (di payload event) dan `hears`, yang isinya khusus eskalasi —
 * artinya satu-satunya jejak Pandu yang bisa dibaca dari database adalah
 * kegagalannya, bukan jawabannya.
 *
 * Kolom `origin` mengikuti pola nastari_events dan menjalankan fungsi yang
 * sama, mencegah hitungan ganda:
 *   'live'   = ditulis HearService saat pertanyaan dijawab.
 *   'ingest' = dipulihkan NastariEventIngestor dari baris PANDU_DEBUG di
 *              storage/logs, untuk riwayat sebelum pencatatan langsung.
 *
 * Isinya konten, bukan metrik: metrik Pandu tetap dihitung dari
 * nastari_events. Tabel ini yang membuat pertanyaan dan jawabannya bisa dibaca
 * dan ditelusuri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pandu_qa', function (Blueprint $table) {
            $table->id();

            $table->string('origin', 10)->default('live');

            $table->dateTime('asked_at');
            $table->date('ask_date');
            $table->unsignedTinyInteger('ask_hour');

            $table->string('phone', 25)->nullable();
            $table->string('employee_id', 25)->nullable();
            $table->string('employee_name', 120)->nullable();
            $table->string('business_unit', 60)->nullable();

            $table->text('question');
            $table->text('answer')->nullable();

            // answered | not_found | no_response | error — kosakata yang sama
            // dengan outcome di nastari_events.
            $table->string('outcome', 20)->default('answered');

            $table->unsignedInteger('duration_ms')->nullable();

            // Terisi 'ruang' kalau pertanyaannya datang dari serah-terima
            // Ruang -> Pandu, bukan diketik langsung di Pandu.
            $table->string('handoff_from', 20)->nullable();

            // Terisi kalau pertanyaan ini akhirnya dieskalasi menjadi tiket;
            // jawaban HCO-nya ada di hears.answer.
            $table->string('ticket_code', 40)->nullable();

            $table->char('fingerprint', 40)->unique();

            $table->timestamps();

            $table->index('ask_date');
            $table->index(['outcome', 'ask_date']);
            $table->index(['business_unit', 'ask_date']);
            $table->index(['employee_id', 'ask_date']);
            $table->index('ticket_code');
            $table->index('origin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pandu_qa');
    }
};
