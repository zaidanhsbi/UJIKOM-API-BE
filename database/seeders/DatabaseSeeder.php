<?php

namespace Database\Seeders;

use App\Models\License;
use App\Models\Rombel;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Menjalankan seeder data dasar sekolah, lisensi, dan akun uji untuk test suite.
     */
    public function run(): void
    {
        $school1 = School::firstOrCreate(
            ['npsn' => '20201234'],
            [
                'name' => 'SMA Negeri 1 BinoExam',
                'is_active' => true,
            ]
        );

        License::firstOrCreate(
            ['school_id' => $school1->id],
            [
                'plan' => 'pro',
                'max_students' => 1000,
                'starts_at' => now(),
                'status' => 'active',
                'expires_at' => now()->addYear(),
            ]
        );

        $rombel1 = Rombel::firstOrCreate(
            ['school_id' => $school1->id, 'name' => 'XII IPA 1'],
            ['academic_year' => '2025/2026']
        );

        Subject::firstOrCreate(
            ['school_id' => $school1->id, 'code' => 'MP-MAT'],
            ['name' => 'Matematika']
        );

        User::firstOrCreate(
            ['school_id' => $school1->id, 'username' => 'admin'],
            [
                'role' => 'admin',
                'name' => 'Admin Sekolah 1',
                'email' => 'admin@20201234.sch.id',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['school_id' => $school1->id, 'username' => 'GR-001'],
            [
                'role' => 'guru',
                'name' => 'Guru Sekolah 1',
                'email' => 'guru1@20201234.sch.id',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['school_id' => $school1->id, 'nisn' => '0081234501'],
            [
                'rombel_id' => $rombel1->id,
                'role' => 'siswa',
                'name' => 'Siswa Satu',
                'username' => '0081234501',
                'email' => 'siswa1@20201234.sch.id',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['school_id' => $school1->id, 'nisn' => '0081234502'],
            [
                'rombel_id' => $rombel1->id,
                'role' => 'siswa',
                'name' => 'Siswa Dua',
                'username' => '0081234502',
                'email' => 'siswa2@20201234.sch.id',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $school2 = School::firstOrCreate(
            ['npsn' => '20209999'],
            [
                'name' => 'SMA Negeri 2 BinoExam',
                'is_active' => true,
            ]
        );

        License::firstOrCreate(
            ['school_id' => $school2->id],
            [
                'plan' => 'pro',
                'max_students' => 1000,
                'starts_at' => now(),
                'status' => 'active',
                'expires_at' => now()->addYear(),
            ]
        );

        User::firstOrCreate(
            ['school_id' => $school2->id, 'username' => 'admin2'],
            [
                'role' => 'admin',
                'name' => 'Admin Sekolah 2',
                'email' => 'admin@20209999.sch.id',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );
    }
}
