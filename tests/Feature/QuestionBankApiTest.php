<?php

namespace Tests\Feature;

use App\Models\QuestionBank;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionBankApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_non_guru_cannot_access_question_banks(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $admin = User::where('school_id', $school->id)->where('role', 'admin')->first();

        Sanctum::actingAs($admin);

        $this->getJson('/api/guru/question-banks')->assertStatus(403);
    }

    public function test_guru_can_crud_question_banks_and_filter(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $guru = User::where('school_id', $school->id)->where('role', 'guru')->first();
        $subject = Subject::where('school_id', $school->id)->first();

        Sanctum::actingAs($guru);

        // Create
        $createRes = $this->postJson('/api/guru/question-banks', [
            'name' => 'UTS Matematika Ganjil',
            'subject_id' => $subject->id,
            'grade' => '10',
            'visibility' => 'private',
        ])->assertStatus(201);

        $bankId = $createRes->json('question_bank.id');
        $this->assertEquals('UTS Matematika Ganjil', $createRes->json('question_bank.name'));

        // List private tab with search & grade filter
        $this->getJson('/api/guru/question-banks?tab=private&grade=10&search=UTS')
            ->assertStatus(200)
            ->assertJsonCount(1, 'question_banks')
            ->assertJsonPath('question_banks.0.id', $bankId);

        // Update visibility to school
        $this->putJson("/api/guru/question-banks/{$bankId}", [
            'visibility' => 'school',
        ])->assertStatus(200)
            ->assertJsonPath('question_bank.visibility', 'school');

        // Delete
        $this->deleteJson("/api/guru/question-banks/{$bankId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('question_banks', ['id' => $bankId]);
    }

    public function test_guru_can_duplicate_school_bank_from_other_teacher(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $guruA = User::where('school_id', $school->id)->where('role', 'guru')->first();
        $guruB = User::create([
            'school_id' => $school->id,
            'role' => 'guru',
            'name' => 'Guru B',
            'username' => 'guru.b.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $subject = Subject::where('school_id', $school->id)->first();

        $sharedBank = QuestionBank::create([
            'school_id' => $school->id,
            'owner_id' => $guruB->id,
            'name' => 'Bank Soal Bersama',
            'subject_id' => $subject->id,
            'grade' => '11',
            'visibility' => 'school',
        ]);

        $privateBank = QuestionBank::create([
            'school_id' => $school->id,
            'owner_id' => $guruB->id,
            'name' => 'Bank Soal Rahasia',
            'subject_id' => $subject->id,
            'grade' => '11',
            'visibility' => 'private',
        ]);

        Sanctum::actingAs($guruA);

        // Tab Bank Sekolah hanya menampilkan milik guru lain yang visibility = school
        $this->getJson('/api/guru/question-banks?tab=school')
            ->assertStatus(200)
            ->assertJsonCount(1, 'question_banks')
            ->assertJsonPath('question_banks.0.id', $sharedBank->id);

        // Guru A tidak boleh edit/hapus/lihat paket private guru B
        $this->getJson("/api/guru/question-banks/{$privateBank->id}")->assertStatus(403);
        $this->putJson("/api/guru/question-banks/{$sharedBank->id}", ['name' => 'Hack'])->assertStatus(403);
        $this->deleteJson("/api/guru/question-banks/{$sharedBank->id}")->assertStatus(403);

        // Guru A duplikasi paket Bank Sekolah milik Guru B
        $dupRes = $this->postJson("/api/guru/question-banks/{$sharedBank->id}/duplicate", [
            'name' => 'Salinan Bank Bersama',
        ])->assertStatus(201);

        $dupRes->assertJsonPath('question_bank.name', 'Salinan Bank Bersama')
            ->assertJsonPath('question_bank.owner_id', $guruA->id)
            ->assertJsonPath('question_bank.source_id', $sharedBank->id)
            ->assertJsonPath('question_bank.visibility', 'private');
    }
}
