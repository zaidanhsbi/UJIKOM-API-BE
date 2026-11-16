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

class QuestionApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_guru_can_create_all_five_question_types_and_inline_bank(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $guru = User::where('school_id', $school->id)->where('role', 'guru')->first();
        $subject = Subject::where('school_id', $school->id)->first();

        Sanctum::actingAs($guru);

        // 1. PG + inline bank creation
        $pgRes = $this->postJson('/api/guru/questions', [
            'new_bank' => [
                'name' => 'Paket Inline Baru',
                'subject_id' => $subject->id,
                'grade' => '10A',
                'visibility' => 'private',
            ],
            'type' => 'pg',
            'difficulty' => 'mudah',
            'weight' => 2,
            'explanation' => 'Pembahasan PG',
            'content' => [
                'text' => "Baris 1\nBaris 2",
                'options' => [
                    'A' => ['text' => 'Opsi A', 'image' => null],
                    'B' => ['text' => 'Opsi B', 'image' => 'question-media/1/b.png'],
                    'C' => ['text' => 'Opsi C', 'image' => null],
                    'D' => ['text' => 'Opsi D', 'image' => null],
                    'E' => ['text' => 'Opsi E', 'image' => null],
                ],
                'answer' => 'B',
            ],
        ])->assertStatus(201);

        $bankId = $pgRes->json('bank_id');
        $pgId = $pgRes->json('question.id');
        $this->assertEquals("Baris 1\nBaris 2", $pgRes->json('question.content.text'));
        $this->assertEquals('question-media/1/b.png', $pgRes->json('question.content.options.B.image'));

        // 2. PGK
        $this->postJson('/api/guru/questions', [
            'bank_id' => $bankId,
            'type' => 'pgk',
            'difficulty' => 'sedang',
            'weight' => 3,
            'content' => [
                'text' => 'Pilih dua jawaban benar',
                'options' => [
                    'A' => ['text' => 'Satu', 'image' => null],
                    'B' => ['text' => 'Dua', 'image' => null],
                    'C' => ['text' => 'Tiga', 'image' => null],
                    'D' => ['text' => 'Empat', 'image' => null],
                    'E' => ['text' => 'Lima', 'image' => null],
                ],
                'answers' => ['A', 'C'],
            ],
        ])->assertStatus(201);

        // 3. BS
        $this->postJson('/api/guru/questions', [
            'bank_id' => $bankId,
            'type' => 'bs',
            'difficulty' => 'mudah',
            'weight' => 1,
            'content' => [
                'text' => 'Matahari terbit dari timur.',
                'answer' => 'true',
            ],
        ])->assertStatus(201);

        // 4. JD
        $this->postJson('/api/guru/questions', [
            'bank_id' => $bankId,
            'type' => 'jd',
            'difficulty' => 'sukar',
            'weight' => 4,
            'content' => [
                'text' => 'Pasangkan ibukota berikut',
                'pairs' => [
                    ['left' => 'Indonesia', 'right' => 'Jakarta'],
                    ['left' => 'Jepang', 'right' => 'Tokyo'],
                ],
            ],
        ])->assertStatus(201);

        // 5. UR
        $this->postJson('/api/guru/questions', [
            'bank_id' => $bankId,
            'type' => 'ur',
            'difficulty' => 'sedang',
            'weight' => 3,
            'content' => [
                'text' => 'Urutkan bilangan dari terkecil',
                'items' => ['1', '2', '3'],
            ],
        ])->assertStatus(201);

        // Update PG increments version
        $this->putJson("/api/guru/questions/{$pgId}", [
            'weight' => 5,
        ])->assertStatus(200)
            ->assertJsonPath('question.version', 2);

        // Delete PG
        $this->deleteJson("/api/guru/questions/{$pgId}")
            ->assertStatus(200);
    }

    public function test_guru_can_bulk_import_questions_from_excel_rows(): void
    {
        $school = School::where('npsn', '20201234')->first();
        $guru = User::where('school_id', $school->id)->where('role', 'guru')->first();
        $subject = Subject::where('school_id', $school->id)->first();

        $bank = QuestionBank::create([
            'school_id' => $school->id,
            'owner_id' => $guru->id,
            'name' => 'Paket Import',
            'subject_id' => $subject->id,
            'grade' => '10A',
            'visibility' => 'private',
        ]);

        Sanctum::actingAs($guru);

        $res = $this->postJson("/api/guru/question-banks/{$bank->id}/import", [
            'rows' => [
                [
                    'type' => 'PG',
                    'text' => '1 + 1 = ?',
                    'option_a' => '1',
                    'option_b' => '2',
                    'option_c' => '3',
                    'option_d' => '4',
                    'option_e' => '5',
                    'answer' => 'B',
                    'weight' => 2,
                ],
                [
                    'type' => 'BS',
                    'text' => 'Air mendidih pada 100C.',
                    'answer' => 'B',
                    'weight' => 1,
                ],
                [
                    'type' => 'PG',
                    'text' => 'Soal tanpa kunci',
                    'option_a' => 'A',
                    'option_b' => 'B',
                    'answer' => '',
                    'weight' => 1,
                ],
            ],
        ])->assertStatus(200);

        $res->assertJsonPath('imported_count', 2)
            ->assertJsonCount(1, 'errors');
    }

    public function test_guru_docx_import_template_exists_and_can_be_downloaded(): void
    {
        $templatePath = storage_path('app/templates/Template_Import_Soal_BinoExam.docx');
        $this->assertFileExists($templatePath);

        $school = School::where('npsn', '20201234')->first();
        $guru = User::where('school_id', $school->id)->where('role', 'guru')->first();

        Sanctum::actingAs($guru);

        $res = $this->get('/api/guru/question-banks/template-docx');
        $res->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
}
