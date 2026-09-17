<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('usernastari', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->unique();
            $table->string('whatsapp_number')->index();
            $table->string('full_name');
            $table->string('group_company')->nullable();
            $table->string('contribution_level')->nullable();
            $table->string('unit_name')->nullable();
            $table->string('designation_name')->nullable();
            $table->string('job_level')->nullable();
            $table->string('office_area')->nullable();
            $table->string('company_email_id')->nullable();
            $table->date('date_of_joining')->nullable();
            $table->string('direct_manager_id')->nullable();
            $table->string('direct_manager_name')->nullable();
            $table->string('l2_manager_id')->nullable();
            $table->string('l2_manager_name')->nullable();
            $table->string('employee_type')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('usernastari');
    }
};