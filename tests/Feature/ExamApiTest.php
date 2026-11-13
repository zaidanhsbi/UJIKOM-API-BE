<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Rombel;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_guru_can_create_publish_start_finish_and_delete_exam_without_leaking_answers(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $guru = User::where('school_id', $school->id)->where('role', 'guru')->first();
        $subject = Subject::where('school_id', $school->id)->first();
        $rombel = Rombel::where('school_id', $school->id)->first();

        $bank = QuestionBank::create([
            'school_id' => $school->id,
            'owner_id' => $guru->id,
            'name' => 'Bank Ujian',
            'subject_id' => $subject->id,
            'grade' => $rombel->name,
            'visibility' => 'private',
        ]);

        Question::create([
            'school_id' => $school->id,
            'bank_id' => $bank->id,
            'type' => 'pg',
            'difficulty' => 'sedang',
            'content' => [
                'text' => '2 + 2 = ?',
                'options' => [
                    'A' => ['text' => '3', 'image' => null],
                    'B' => ['text' => '4', 'image' => null],
                    'C' => ['text' => '5', 'image' => null],
                    'D' => ['text' => '6', 'image' => null],
                    'E' => ['text' => '7', 'image' => null],
                ],
                'answer' => 'B',
            ],
            'weight' => 1,
            'explanation' => 'Kunci adalah B',
            'version' => 1,
        ]);

        Sanctum::actingAs($guru);

        // Create & publish
        $res = $this->postJson('/api/guru/exams', [
            'name' => 'PTS Matematika',
            'subject_id' => $subject->id,
            'rombel_ids' => [$rombel->id],
            'question_bank_id' => $bank->id,
            'duration_minutes' => 90,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addMinutes(120)->toIso8601String(),
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'action' => 'publish',
        ])->assertStatus(201);

        $examId = $res->json('exam.id');
        $this->assertEquals('scheduled', $res->json('exam.status'));
        $this->assertNotEmpty($res->json('exam.token'));

        // Pastikan file paket soal ada dan TIDAK mengandung kunci jawaban
        $packagePath = "exam-packages/exam_{$examId}_v1.json";
        Storage::disk('local')->assertExists($packagePath);
        $packageJson = json_decode(Storage::disk('local')->get($packagePath), true);
        $this->assertArrayNotHasKey('answer', $packageJson['questions'][0]['content']);
        $this->assertArrayNotHasKey('answers', $packageJson['questions'][0]['content']);

        // Start exam
        $this->postJson("/api/guru/exams/{$examId}/start")
            ->assertStatus(200)
            ->assertJsonPath('exam.status', 'ongoing');

        // Cannot delete ongoing exam
        $this->deleteJson("/api/guru/exams/{$examId}")->assertStatus(422);

        // Finish exam
        $this->postJson("/api/guru/exams/{$examId}/finish")
            ->assertStatus(200)
            ->assertJsonPath('exam.status', 'finished');

        // Can delete finished exam
        $this->deleteJson("/api/guru/exams/{$examId}")->assertStatus(200);
    }
}
