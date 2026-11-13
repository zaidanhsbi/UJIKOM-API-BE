<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->enum('type', ['pg', 'pgk', 'bs', 'jd', 'ur']);
            $table->enum('difficulty', ['mudah', 'sedang', 'sukar'])->default('sedang');
            $table->text('content');
            $table->unsignedInteger('weight')->default(1);
            $table->text('explanation')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['school_id', 'bank_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
