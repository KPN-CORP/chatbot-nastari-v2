<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCallCentersTable extends Migration
{
    public function up()
    {
        Schema::create('call_centers', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_code')->unique();
            $table->string('employee_id')->index();
            $table->string('business_unit')->nullable();
            $table->string('name')->nullable();
            $table->string('mobile')->nullable();
            $table->text('complaint');
            $table->string('image_path')->nullable();
            $table->string('status')->default('Open');
            $table->dateTime('ticket_date');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('call_centers');
    }
}