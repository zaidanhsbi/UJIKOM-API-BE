<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('questions')->cascadeOnDelete();
            $table->string('file');
            $table->string('alt_text')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_media');
    }
};
