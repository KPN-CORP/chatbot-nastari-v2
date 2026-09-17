<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic per-scope counters for letter numbers.
 *
 * Letter numbers were derived from `LetterLog::count() + 1`, which is wrong in
 * two ways: two letters generated in the same second get the same number, and
 * deleting any letter_logs row makes the next number collide with an existing
 * one. A scope is the number's own namespace, e.g. "SK/PLT/09-2026".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 60)->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_number_sequences');
    }
};
