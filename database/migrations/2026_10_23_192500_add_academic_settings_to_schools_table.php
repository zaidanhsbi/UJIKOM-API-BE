<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('active_academic_year', 20)->default('2026/2027')->after('npsn');
            $table->string('active_semester', 20)->default('Ganjil')->after('active_academic_year');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['active_academic_year', 'active_semester']);
        });
    }
};
