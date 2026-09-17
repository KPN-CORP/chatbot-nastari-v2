<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_chat_logs', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->index();
            $table->string('employee_name');
            $table->string('business_unit')->index();
            $table->string('phone');
            $table->date('date')->index(); 
            $table->json('messages'); 
            $table->timestamps();
            
            $table->unique(['employee_id', 'date']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_chat_logs');
    }
};