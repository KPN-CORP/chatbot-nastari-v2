<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hco_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('work_area_code');
            $table->string('work_area_name');
            $table->string('bu_name');
            $table->string('hco_employee_id');
            $table->string('hco_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hco_mappings');
    }
};
