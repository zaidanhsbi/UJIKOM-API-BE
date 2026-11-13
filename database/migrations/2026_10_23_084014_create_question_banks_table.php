<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('grade', 50);
            $table->enum('visibility', ['private', 'school'])->default('private');
            $table->foreignId('source_id')->nullable()->constrained('question_banks')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'owner_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_banks');
    }
};
