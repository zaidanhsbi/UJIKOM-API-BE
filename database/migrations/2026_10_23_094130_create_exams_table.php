<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->string('name');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('token', 12)->nullable()->unique();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->enum('status', ['draft', 'scheduled', 'ongoing', 'finished'])->default('draft');
            $table->timestamps();

            $table->index(['school_id', 'teacher_id', 'status']);
        });

        Schema::create('exam_rombels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('rombel_id')->constrained('rombels')->cascadeOnDelete();
            $table->unique(['exam_id', 'rombel_id']);
        });

        Schema::create('exam_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('sha256', 64);
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['ready', 'failed'])->default('ready');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_packages');
        Schema::dropIfExists('exam_rombels');
        Schema::dropIfExists('exams');
    }
};
