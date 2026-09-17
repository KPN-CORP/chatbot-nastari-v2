<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_logs', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->index();
            $table->string('employee_name');
            $table->string('business_unit')->index();
            $table->string('phone');
            $table->string('sender', 10);
            $table->text('message');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_logs');
    }
};