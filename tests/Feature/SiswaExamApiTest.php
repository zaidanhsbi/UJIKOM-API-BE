<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Rombel;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiswaExamApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_siswa_can_list_start_and_submit_exam_with_grading(): void
    {
        $school = School::create([
            'name' => 'SMA Negeri 1 Test',
            'npsn' => '99887766',
            'license_status' => 'active',
        ]);

        $rombel = Rombel::create([
            'school_id' => $school->id,
            'name' => 'XII IPA 1',
            'grade' => '12',
        ]);

        $subject = Subject::create([
            'school_id' => $school->id,
            'name' => 'Matematika',
            'code' => 'MTK',
        ]);

        $guru = User::create([
            'school_id' => $school->id,
            'role' => 'guru',
            'name' => 'Guru Test',
            'username' => 'gurutest',
            'email' => 'gurutest@test.local',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $siswa = User::create([
            'school_id' => $school->id,
            'rombel_id' => $rombel->id,
            'role' => 'siswa',
            'name' => 'Siswa Test',
            'username' => 'siswatest',
            'nisn' => '1234567890',
            'email' => 'siswatest@test.local',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $bank = QuestionBank::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'owner_id' => $guru->id,
            'name' => 'Bank Matematika',
            'grade' => '12',
            'visibility' => 'school',
        ]);

        $q1 = Question::create([
            'school_id' => $school->id,
            'bank_id' => $bank->id,
            'type' => 'pg',
            'difficulty' => 'mudah',
            'weight' => 50,
            'content' => [
                'text' => '2 + 2 = ...',
                'options' => [
                    'A' => ['text' => '3'],
                    'B' => ['text' => '4'],
                ],
                'answer' => 'B',
            ],
        ]);

        $q2 = Question::create([
            'school_id' => $school->id,
            'bank_id' => $bank->id,
            'type' => 'bs',
            'difficulty' => 'mudah',
            'weight' => 50,
            'content' => [
                'text' => '5 > 3 adalah pernyataan benar.',
                'answer' => 'Benar',
            ],
        ]);

        $exam = Exam::create([
            'school_id' => $school->id,
            'teacher_id' => $guru->id,
            'subject_id' => $subject->id,
            'question_bank_id' => $bank->id,
            'name' => 'Ujian Matematika Harian',
            'duration_minutes' => 60,
            'token' => 'ABC123',
            'status' => 'ongoing',
        ]);
        $exam->rombels()->attach($rombel->id);

        Sanctum::actingAs($siswa);

        $this->getJson('/api/siswa/exams')
            ->assertStatus(200)
            ->assertJsonCount(1, 'exams')
            ->assertJsonPath('exams.0.name', 'Ujian Matematika Harian')
            ->assertJsonPath('exams.0.already_submitted', false);

        $startRes = $this->postJson("/api/siswa/exams/{$exam->id}/start", [
            'token' => 'abc123',
        ])->assertStatus(200)
            ->assertJsonPath('exam_id', $exam->id)
            ->assertJsonCount(2, 'questions');

        $this->assertArrayNotHasKey('answer', $startRes->json('questions.0.content'));

        $this->postJson("/api/siswa/exams/{$exam->id}/submit", [
            'duration_seconds' => 120,
            'answers' => [
                ['question_id' => $q1->id, 'answer' => 'B', 'ver' => 1],
                ['question_id' => $q2->id, 'answer' => 'Salah', 'ver' => 1],
            ],
        ])->assertStatus(200)
            ->assertJsonPath('score', 50);

        $this->getJson('/api/siswa/exams')
            ->assertStatus(200)
            ->assertJsonPath('exams.0.already_submitted', true);

        Sanctum::actingAs($guru);

        $this->getJson('/api/guru/recaps')
            ->assertStatus(200)
            ->assertJsonCount(1, 'exams')
            ->assertJsonPath('exams.0.id', $exam->id)
            ->assertJsonPath('exams.0.submissions_count', 1)
            ->assertJsonPath('exams.0.average_score', 50);

        $this->getJson("/api/guru/recaps/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('summary.total_students', 1)
            ->assertJsonPath('summary.average', 50)
            ->assertJsonPath('students.0.score', 50);
    }
}
