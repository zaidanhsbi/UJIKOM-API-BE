<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rombels', function (Blueprint $table) {
            $table->string('academic_year', 20)->default('2026/2027')->after('name')->index();
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->string('academic_year', 20)->default('2026/2027')->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('rombels', function (Blueprint $table) {
            $table->dropIndex(['academic_year']);
            $table->dropColumn('academic_year');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropIndex(['academic_year']);
            $table->dropColumn('academic_year');
        });
    }
};
