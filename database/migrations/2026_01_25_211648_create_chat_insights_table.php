<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chat_insights', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('period_type')->default('weekly'); 
            $table->integer('total_chats')->default(0);
            $table->integer('total_users')->default(0);
            
            $table->string('dominant_sentiment')->nullable(); 
            $table->json('top_topics')->nullable(); 
            $table->text('summary')->nullable(); 
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('chat_insights');
    }
};