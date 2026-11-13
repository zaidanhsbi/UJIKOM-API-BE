<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\Rombel;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RombelAndStudentApiTest extends TestCase
{
    use DatabaseTransactions;

    private function createSchoolWithUsers(): array
    {
        $school = School::create([
            'name' => 'SMA Negeri Rombel Test',
            'npsn' => '88887777',
            'is_active' => true,
        ]);

        License::create([
            'school_id' => $school->id,
            'plan' => 'Pro',
            'max_students' => 500,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ]);

        $admin = User::create([
            'school_id' => $school->id,
            'role' => 'admin',
            'name' => 'Admin Rombel',
            'username' => 'admin_rombel',
            'email' => 'admin@rombeltest.sch.id',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        return [$school, $admin];
    }

    public function test_admin_can_crud_rombels_and_unique_name_per_school(): void
    {
        [$school, $admin] = $this->createSchoolWithUsers();
        $token = $admin->createToken('test')->plainTextToken;

        // 1. Create Rombel
        $createRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/rombels', [
                'name' => 'XII IPA 1',
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.name', 'XII IPA 1');

        $rombelId = $createRes->json('data.id');

        // 2. Duplicate Rombel name in same school rejected
        $dupRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/rombels', [
                'name' => 'XII IPA 1',
            ]);

        $dupRes->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 3. Update Rombel
        $updateRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/admin/rombels/{$rombelId}", [
                'name' => 'XII IPA 2',
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'XII IPA 2');

        // 4. Delete Rombel
        $delRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/admin/rombels/{$rombelId}");

        $delRes->assertStatus(200);
    }

    public function test_admin_can_crud_students_filter_by_rombel_and_cascade_delete(): void
    {
        [$school, $admin] = $this->createSchoolWithUsers();
        $token = $admin->createToken('test')->plainTextToken;

        $rombelA = Rombel::create([
            'school_id' => $school->id,
            'name' => 'X RPL 1',
        ]);

        $rombelB = Rombel::create([
            'school_id' => $school->id,
            'name' => 'X RPL 2',
        ]);

        // 1. Create Student in Rombel A
        $studentARes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/students', [
                'nisn' => '0099887701',
                'name' => 'Budi Pratama',
                'rombel_id' => $rombelA->id,
                'password' => 'siswa123',
            ]);

        $studentARes->assertStatus(201)
            ->assertJsonPath('data.nisn', '0099887701')
            ->assertJsonPath('data.rombel.name', 'X RPL 1');

        $studentAId = $studentARes->json('data.id');

        // 2. Create Student in Rombel B
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/students', [
                'nisn' => '0099887702',
                'name' => 'Rina Oktavia',
                'rombel_id' => $rombelB->id,
                'password' => 'siswa123',
            ])->assertStatus(201);

        // 3. Filter Students by Rombel A
        $filterRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/admin/students?rombel_id={$rombelA->id}");

        $filterRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Budi Pratama');

        // 4. Update Student to move to Rombel B
        $updateRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/admin/students/{$studentAId}", [
                'nisn' => '0099887701',
                'name' => 'Budi Pratama Updated',
                'rombel_id' => $rombelB->id,
                'password' => '',
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Budi Pratama Updated')
            ->assertJsonPath('data.rombel.name', 'X RPL 2');

        // 5. Cascade Delete: Deleting Rombel B removes its students
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/admin/rombels/{$rombelB->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $studentAId]);
    }

    public function test_admin_can_bulk_import_rombels_students_and_manage_teachers(): void
    {
        [$school, $admin] = $this->createSchoolWithUsers();
        $token = $admin->createToken('test')->plainTextToken;

        // 1. Bulk import Rombels
        $rombelImport = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/rombels/bulk-import', [
                'rombels' => [
                    ['name' => 'XI TKJ 1'],
                    ['name' => 'XI TKJ 2'],
                    ['name' => 'XI TKJ 1'], // duplicate
                ],
            ]);

        $rombelImport->assertStatus(201)
            ->assertJsonPath('summary.imported', 2)
            ->assertJsonPath('summary.skipped', 1);

        // 2. Bulk import Students (with password_plain and auto-created Rombel)
        $studentImport = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/students/bulk-import', [
                'students' => [
                    ['nisn' => '1122334455', 'name' => 'Andi Wijaya', 'rombel' => 'XI TKJ 1', 'password' => 'passAndi99'],
                    ['nisn' => '1122334456', 'name' => 'Siti Aminah', 'rombel' => 'XII DKV 1', 'password' => ''],
                ],
            ]);

        $studentImport->assertStatus(201)
            ->assertJsonPath('summary.imported', 2);

        $this->assertDatabaseHas('users', [
            'nisn' => '1122334455',
            'password_plain' => 'passAndi99',
        ]);

        // 3. Create & Bulk import Teachers with password_plain
        $teacherCreate = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/teachers', [
                'name' => 'Drs. Hendra Gunawan',
                'username' => 'hendra_guru',
                'email' => 'hendra@sekolah.sch.id',
                'password' => 'guruHendra1',
            ]);

        $teacherCreate->assertStatus(201)
            ->assertJsonPath('data.password_plain', 'guruHendra1');

        $teacherImport = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/teachers/bulk-import', [
                'teachers' => [
                    ['name' => 'Dra. Ratna Sari', 'username' => 'ratna_guru', 'email' => 'ratna@sekolah.sch.id', 'password' => 'ratnaPass123'],
                    ['name' => 'Duplikat Hendra', 'username' => 'hendra_guru', 'email' => 'hendra2@sekolah.sch.id', 'password' => 'guru123'],
                ],
            ]);

        $teacherImport->assertStatus(201)
            ->assertJsonPath('summary.imported', 1)
            ->assertJsonPath('summary.skipped', 1);
    }
}


