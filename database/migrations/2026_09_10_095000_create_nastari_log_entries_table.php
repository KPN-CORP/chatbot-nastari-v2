<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan entri ERROR ke atas dari storage/logs supaya tetap bisa ditelusuri
 * setelah file lognya dirotasi.
 *
 * Kenapa tidak dimasukkan ke nastari_events saja: tabel itu adalah sumber
 * metrik, dan sebagian error sudah punya baris terstrukturnya sendiri yang
 * ditulis NastariActivityLogger. Menuangkan baris log ke tabel yang sama akan
 * menghitung kejadian yang sama dua kali pada setiap angka error. Tabel ini
 * bersifat diagnostik: ditampilkan sebagai bloknya sendiri di dashboard dan
 * sengaja tidak dijumlahkan dengan metrik error.
 *
 * Yang hanya ada di sini dan tidak mungkin ada di nastari_events: fatal error
 * PHP. Prosesnya mati sebelum shutdown handler bisa menulis apa pun — persis
 * seperti yang terjadi pada kasus memori habis di generate surat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nastari_log_entries', function (Blueprint $table) {
            $table->id();

            $table->dateTime('logged_at');
            $table->date('entry_date');
            $table->unsignedTinyInteger('entry_hour');

            $table->string('level', 12);          // ERROR | CRITICAL | ALERT | EMERGENCY
            $table->string('channel', 24)->nullable(); // bagian sebelum titik: local, production, …

            // Label berbahasa Indonesia hasil klasifikasi, untuk pengelompokan
            // yang bisa dibaca manusia di dashboard.
            $table->string('kind', 60)->nullable();

            // sha1 dari pesan yang sudah dinormalkan (angka dan path dibuang),
            // supaya seribu kejadian yang sama mengelompok menjadi satu baris.
            $table->char('signature', 40);
            $table->string('title', 200);

            $table->text('message');

            $table->string('exception', 160)->nullable();
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();

            $table->string('source_file', 60);

            // sha1 dari file sumber + waktu + level + awal pesan. Membuat
            // pemindaian ulang tidak pernah menduplikasi.
            $table->char('fingerprint', 40)->unique();

            $table->timestamp('created_at')->nullable();

            $table->index('entry_date');
            $table->index(['level', 'entry_date']);
            $table->index(['signature', 'entry_date']);
            $table->index(['entry_date', 'entry_hour']);
        });

        /*
         * Posisi baca terakhir per file.
         *
         * Inilah yang membuat pemindaian tiap 10 menit tetap murah: hanya byte
         * baru yang dibaca, bukan seluruh file. Tanpa ini, satu file log 200 MB
         * akan dibaca ulang seluruhnya setiap sepuluh menit — logging berubah
         * menjadi beban, yang justru harus dihindari.
         */
        Schema::create('nastari_log_scans', function (Blueprint $table) {
            $table->id();
            $table->string('source_file', 60)->unique();
            $table->unsignedBigInteger('last_offset')->default(0);
            $table->unsignedBigInteger('last_size')->default(0);
            $table->unsignedInteger('entries_seen')->default(0);
            $table->dateTime('last_scanned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nastari_log_scans');
        Schema::dropIfExists('nastari_log_entries');
    }
};
