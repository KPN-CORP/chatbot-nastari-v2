<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('letter_logs', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_surat')->nullable();
            $table->string('jenis_surat')->nullable();
            $table->string('nik')->nullable();
            $table->string('nama_karyawan')->nullable();
            $table->string('kebutuhan')->nullable();
            $table->string('institusi')->nullable();
            $table->string('penanda_tangan')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('letter_logs');
    }
};