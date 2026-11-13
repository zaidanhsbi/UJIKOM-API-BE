<?php

namespace App\Services\Siswa;

use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamSubmission;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentExamService
{
    private const SECONDS_PER_MINUTE = 60;
    private const MAX_SCORE_SCALE = 100;

    /**
     * Mengambil daftar ujian yang tersedia bagi siswa beserta status pengumpulannya.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listAvailableExams(User $student): Collection
    {
        $exams = Exam::query()
            ->visibleToStudent($student)
            ->with([
                'subject:id,name,code',
                'questionBank' => fn ($q) => $q->select('id')->withCount('questions'),
            ])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        $submittedExamIds = ExamSubmission::query()
            ->where('student_id', $student->id)
            ->whereNotNull('finished_at')
            ->pluck('exam_id')
            ->flip();

        return $exams->map(function (Exam $exam) use ($submittedExamIds) {
            return [
                'id' => $exam->id,
                'name' => $exam->name,
                'subject' => $exam->subject ? [
                    'id' => $exam->subject->id,
                    'name' => $exam->subject->name,
                    'code' => $exam->subject->code,
                ] : null,
                'duration_minutes' => $exam->duration_minutes,
                'questions_count' => (int) ($exam->questionBank?->questions_count ?? 0),
                'status' => $exam->status,
                'starts_at' => $exam->starts_at?->toIso8601String(),
                'ends_at' => $exam->ends_at?->toIso8601String(),
                'already_submitted' => $submittedExamIds->has($exam->id),
            ];
        })->values();
    }

    /**
     * Memulai atau melanjutkan sesi ujian siswa serta mengembalikan paket soal tanpa kunci jawaban.
     *
     * @return array<string, mixed>
     */
    public function startExamSession(Exam $exam, User $student): array
    {
        $submission = ExamSubmission::firstOrCreate(
            [
                'exam_id' => $exam->id,
                'student_id' => $student->id,
            ],
            [
                'school_id' => $student->school_id,
                'started_at' => now(),
                'sync_status' => 'in_progress',
            ]
        );

        if ($submission->finished_at !== null) {
            return ['already_finished' => true];
        }

        $remainingSeconds = $this->calculateRemainingSeconds($exam, $submission);
        $questions = $this->loadSanitizedQuestions($exam, $student->id);

        return [
            'already_finished' => false,
            'payload' => [
                'exam_id' => $exam->id,
                'name' => $exam->name,
                'duration_minutes' => $exam->duration_minutes,
                'remaining_seconds' => $remainingSeconds,
                'server_time_epoch_ms' => (int) round(microtime(true) * 1000),
                'questions' => $questions,
            ],
        ];
    }

    /**
     * Menyimpan jawaban siswa, melakukan penilaian otomatis, dan memperbarui skor submission.
     *
     * @param  array<string, mixed>  $validated
     */
    public function submitExamAnswers(Exam $exam, User $student, array $validated): ExamSubmission
    {
        $incomingByQuestion = collect($validated['answers'])
            ->keyBy(fn ($item) => (int) $item['question_id']);

        $questions = $exam->questionBank
            ? $exam->questionBank->questions()->orderBy('id')->get()
            : collect();

        return DB::transaction(function () use ($exam, $student, $validated, $incomingByQuestion, $questions) {
            $submission = ExamSubmission::firstOrCreate(
                [
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                ],
                [
                    'school_id' => $student->school_id,
                    'started_at' => now(),
                    'sync_status' => 'in_progress',
                ]
            );

            $existingAnswersByQuestion = ExamAnswer::query()
                ->where('submission_id', $submission->id)
                ->get()
                ->keyBy('question_id');

            $correctCount = 0;
            $wrongCount = 0;
            $blankCount = 0;
            $totalWeight = 0;
            $earnedWeight = 0;

            foreach ($questions as $question) {
                $weight = max(1, (int) $question->weight);
                $totalWeight += $weight;

                $incoming = $incomingByQuestion->get($question->id);
                $rawAnswer = $incoming['answer'] ?? null;
                $ver = (int) ($incoming['ver'] ?? 1);

                if ($this->isBlankAnswer($rawAnswer)) {
                    $blankCount++;
                    $isCorrect = false;
                    $scoreEarned = 0.0;
                } else {
                    $isCorrect = $this->gradeQuestion($question, $rawAnswer);
                    if ($isCorrect) {
                        $correctCount++;
                        $earnedWeight += $weight;
                        $scoreEarned = (float) $weight;
                    } else {
                        $wrongCount++;
                        $scoreEarned = 0.0;
                    }
                }

                $encodedAnswer = is_string($rawAnswer)
                    ? $rawAnswer
                    : json_encode($rawAnswer, JSON_UNESCAPED_UNICODE);

                $existing = $existingAnswersByQuestion->get($question->id);
                if ($existing === null || $ver >= (int) $existing->ver) {
                    ExamAnswer::updateOrCreate(
                        [
                            'submission_id' => $submission->id,
                            'question_id' => $question->id,
                        ],
                        [
                            'answer' => $encodedAnswer,
                            'is_correct' => $isCorrect,
                            'score_earned' => $scoreEarned,
                            'ver' => $ver,
                        ]
                    );
                }
            }

            $finalScore = $totalWeight > 0
                ? round(($earnedWeight / $totalWeight) * self::MAX_SCORE_SCALE, 2)
                : 0.0;

            $submission->update([
                'finished_at' => $submission->finished_at ?? now(),
                'duration_seconds' => (int) ($validated['duration_seconds'] ?? $submission->duration_seconds ?? 0),
                'correct_count' => $correctCount,
                'wrong_count' => $wrongCount,
                'blank_count' => $blankCount,
                'score' => $finalScore,
                'sync_status' => 'synced',
            ]);

            return $submission;
        });
    }

    /**
     * Menghitung sisa waktu ujian dalam satuan detik berdasarkan waktu mulai dan batas akhir ujian.
     */
    private function calculateRemainingSeconds(Exam $exam, ExamSubmission $submission): int
    {
        $elapsed = $submission->started_at ? $submission->started_at->diffInSeconds(now()) : 0;
        $remainingSeconds = max(0, ($exam->duration_minutes * self::SECONDS_PER_MINUTE) - $elapsed);

        if ($exam->ends_at) {
            $untilEnd = max(0, now()->diffInSeconds($exam->ends_at, false));
            $remainingSeconds = min($remainingSeconds, $untilEnd);
        }

        return (int) $remainingSeconds;
    }

    /**
     * Memuat daftar soal ujian yang sudah dibersihkan dari kunci jawaban.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadSanitizedQuestions(Exam $exam, int $studentId): array
    {
        $questions = [];
        $package = $exam->package;

        if ($package && $package->file_path && Storage::disk('local')->exists($package->file_path)) {
            $decoded = json_decode((string) Storage::disk('local')->get($package->file_path), true);
            $questions = $decoded['questions'] ?? [];
        } elseif ($exam->questionBank) {
            $questions = $exam->questionBank->questions()
                ->orderBy('id')
                ->get()
                ->map(fn (Question $q) => $this->sanitizeSingleQuestion($q))
                ->values()
                ->all();
        }

        if ($exam->shuffle_questions && count($questions) > 1) {
            mt_srand($studentId + $exam->id);
            shuffle($questions);
            mt_srand();
        }

        return array_values($questions);
    }

    /**
     * Menghapus kunci jawaban dari satu item soal sebelum dikirim ke perangkat siswa.
     *
     * @return array<string, mixed>
     */
    private function sanitizeSingleQuestion(Question $question): array
    {
        $content = $question->content ?? [];
        unset($content['answer'], $content['answers']);

        if (isset($content['pairs']) && is_array($content['pairs'])) {
            $content['left_items'] = array_column($content['pairs'], 'left');
            $rightItems = array_column($content['pairs'], 'right');
            shuffle($rightItems);
            $content['right_items'] = $rightItems;
            unset($content['pairs']);
        }

        return [
            'id' => $question->id,
            'type' => $question->type,
            'weight' => $question->weight,
            'content' => $content,
        ];
    }

    /**
     * Memeriksa apakah nilai jawaban siswa kosong (belum dijawab).
     */
    private function isBlankAnswer(mixed $rawAnswer): bool
    {
        return $rawAnswer === null
            || $rawAnswer === ''
            || (is_array($rawAnswer) && count($rawAnswer) === 0);
    }

    /**
     * Memeriksa kebenaran jawaban siswa terhadap kunci jawaban berdasarkan tipe soal.
     */
    private function gradeQuestion(Question $question, mixed $rawAnswer): bool
    {
        $content = $question->content ?? [];

        return match ($question->type) {
            'pg', 'bs' => $this->gradeSingleChoice($content, $rawAnswer),
            'pgk' => $this->gradeMultipleChoice($content, $rawAnswer),
            'jd' => $this->gradeMatchingPairs($content, $rawAnswer),
            default => $this->gradeEssayAnswer($content, $rawAnswer),
        };
    }

    /**
     * Menilai soal pilihan ganda tunggal (PG) atau benar/salah (BS).
     *
     * @param  array<string, mixed>  $content
     */
    private function gradeSingleChoice(array $content, mixed $rawAnswer): bool
    {
        $expected = strtoupper(trim((string) ($content['answer'] ?? '')));
        $actual = strtoupper(trim((string) $rawAnswer));

        return $expected !== '' && $expected === $actual;
    }

    /**
     * Menilai soal pilihan ganda kompleks (PGK).
     *
     * @param  array<string, mixed>  $content
     */
    private function gradeMultipleChoice(array $content, mixed $rawAnswer): bool
    {
        $expected = collect($content['answers'] ?? [])
            ->map(fn ($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->sort()
            ->values()
            ->all();

        $actual = is_array($rawAnswer)
            ? collect($rawAnswer)
                ->map(fn ($v) => strtoupper(trim((string) $v)))
                ->filter()
                ->sort()
                ->values()
                ->all()
            : [];

        return count($expected) > 0 && $expected === $actual;
    }

    /**
     * Menilai soal menjodohkan (JD).
     *
     * @param  array<string, mixed>  $content
     */
    private function gradeMatchingPairs(array $content, mixed $rawAnswer): bool
    {
        $pairs = $content['pairs'] ?? [];
        if (! is_array($pairs) || count($pairs) === 0 || ! is_array($rawAnswer)) {
            return false;
        }

        foreach ($pairs as $pair) {
            $left = (string) ($pair['left'] ?? '');
            $right = (string) ($pair['right'] ?? '');
            if (! isset($rawAnswer[$left]) || trim((string) $rawAnswer[$left]) !== trim($right)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Menilai soal isian/uraian singkat (UR).
     *
     * @param  array<string, mixed>  $content
     */
    private function gradeEssayAnswer(array $content, mixed $rawAnswer): bool
    {
        $expected = mb_strtolower(trim((string) ($content['answer'] ?? '')));
        $actual = mb_strtolower(trim((string) $rawAnswer));

        return $expected !== '' && $expected === $actual;
    }
}
