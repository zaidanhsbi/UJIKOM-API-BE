<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_non_admin_cannot_access_subjects_api(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $guru = User::forSchool($school->id)->where('role', 'guru')->first();

        Sanctum::actingAs($guru);

        $this->getJson('/api/admin/subjects')->assertStatus(403);
        $this->postJson('/api/admin/subjects', [
            'code' => 'MP-KIM',
            'name' => 'Kimia',
        ])->assertStatus(403);
    }

    public function test_admin_can_list_create_update_and_delete_subjects(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $admin = User::forSchool($school->id)->where('role', 'admin')->first();

        Sanctum::actingAs($admin);

        // 1. List
        $initialCount = Subject::forSchool($school->id)->count();
        $listRes = $this->getJson('/api/admin/subjects');
        $listRes->assertStatus(200)
            ->assertJsonCount($initialCount, 'data');

        // 2. Create
        $createRes = $this->postJson('/api/admin/subjects', [
            'code' => 'MP-FIS-TEST',
            'name' => 'Fisika Dasar',
        ]);
        $createRes->assertStatus(201)
            ->assertJsonPath('data.code', 'MP-FIS-TEST')
            ->assertJsonPath('data.name', 'Fisika Dasar');

        $subjectId = $createRes->json('data.id');

        // 3. Duplicate code in same school is rejected
        $dupRes = $this->postJson('/api/admin/subjects', [
            'code' => 'MP-FIS-TEST',
            'name' => 'Fisika Lanjutan',
        ]);
        $dupRes->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // 4. Update
        $updateRes = $this->putJson("/api/admin/subjects/{$subjectId}", [
            'code' => 'MP-FIS-01',
            'name' => 'Fisika Terapan',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.code', 'MP-FIS-01')
            ->assertJsonPath('data.name', 'Fisika Terapan');

        // 5. Delete
        $deleteRes = $this->deleteJson("/api/admin/subjects/{$subjectId}");
        $deleteRes->assertStatus(200);

        $this->assertDatabaseMissing('subjects', ['id' => $subjectId]);
    }

    public function test_subject_code_is_isolated_per_school(): void
    {
        $school1 = School::where('npsn', '20201234')->first();
        $school2 = School::where('npsn', '20209999')->first();

        // Buat MP-MAT di sekolah 2 (seharusnya diizinkan walau di sekolah 1 sudah ada MP-MAT)
        $subjectSchool2 = Subject::create([
            'school_id' => $school2->id,
            'code' => 'MP-MAT',
            'name' => 'Matematika SMA',
        ]);

        $this->assertNotNull($subjectSchool2->id);

        $admin1 = User::forSchool($school1->id)->where('role', 'admin')->first();
        Sanctum::actingAs($admin1);

        // Admin sekolah 1 tidak bisa menghapus subject milik sekolah 2
        $this->deleteJson("/api/admin/subjects/{$subjectSchool2->id}")
            ->assertStatus(404);
    }

    public function test_admin_can_bulk_import_subjects_and_validates_duplicates(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $admin = User::forSchool($school->id)->where('role', 'admin')->first();

        Sanctum::actingAs($admin);

        // 1. Berhasil bulk import
        $res = $this->postJson('/api/admin/subjects/bulk-import', [
            'subjects' => [
                ['row' => 2, 'code' => 'MP-SEJ', 'name' => 'Sejarah Indonesia'],
                ['row' => 3, 'code' => 'MP-GEO', 'name' => 'Geografi'],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('imported_count', 2);

        $this->assertDatabaseHas('subjects', [
            'school_id' => $school->id,
            'code' => 'MP-SEJ',
            'name' => 'Sejarah Indonesia',
        ]);

        // 2. Tolak jika ada kode duplikat yang sudah ada di DB atau kosong
        $failRes = $this->postJson('/api/admin/subjects/bulk-import', [
            'subjects' => [
                ['row' => 2, 'code' => 'MP-SEJ', 'name' => 'Sejarah Duplikat'],
                ['row' => 3, 'code' => 'MP-EKO', 'name' => ''],
            ],
        ]);

        $failRes->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['rows']]);
    }
}

