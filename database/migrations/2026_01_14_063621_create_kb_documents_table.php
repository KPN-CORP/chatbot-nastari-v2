<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('kb_documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path')->unique();
            $table->string('business_unit');
            $table->string('file_size');
            $table->string('status')->default('processing');
            $table->string('upload_by');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kb_documents');
    }
};