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
        Schema::create('exam_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('wrong_count')->default(0);
            $table->unsignedInteger('blank_count')->default(0);
            $table->decimal('score', 6, 2)->default(0);
            $table->string('sync_status', 30)->default('synced');
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
        });

        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('exam_submissions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->text('answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('score_earned', 6, 2)->default(0);
            $table->unsignedInteger('ver')->default(1);
            $table->timestamps();

            $table->unique(['submission_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
        Schema::dropIfExists('exam_submissions');
    }
};
