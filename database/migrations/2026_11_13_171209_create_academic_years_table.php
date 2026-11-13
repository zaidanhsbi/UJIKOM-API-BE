<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 20);
            $table->enum('semester', ['Ganjil', 'Genap'])->default('Ganjil');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();

            $table->unique(['school_id', 'name', 'semester']);
        });

        Schema::table('rombels', function (Blueprint $table) {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('academic_year')
                ->constrained('academic_years')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('rombel_id')
                ->constrained('academic_years')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
        });

        Schema::table('rombels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
        });

        Schema::dropIfExists('academic_years');
    }
};
