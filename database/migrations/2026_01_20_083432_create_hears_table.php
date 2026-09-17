<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('hears', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_code')->nullable();
            $table->dateTime('date');
            $table->string('employee_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('pic')->nullable();
            $table->string('status')->default('Processing');
            $table->string('token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('hears');
    }
};