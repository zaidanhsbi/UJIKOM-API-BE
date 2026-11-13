<?php

namespace App\Services\Guru;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\Rombel;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RecapService
{
    private const DEFAULT_KKM = 75.0;
    private const EASY_THRESHOLD_PERCENT = 70.0;
    private const MEDIUM_THRESHOLD_PERCENT = 30.0;

    /**
     * Mengambil daftar rekap ujian milik guru beserta filter mata pelajaran dan rombel.
     *
     * @return array<string, mixed>
     */
    public function getRecapList(Request $request, int $schoolId, int $teacherId): array
    {
        $query = Exam::query()
            ->recapReady($schoolId, $teacherId)
            ->with(['subject:id,name,code', 'rombels:id,name', 'questionBank:id,name'])
            ->withCount(['rombels', 'submissions'])
            ->withAvg('submissions', 'score');

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->query('subject_id'));
        }

        if ($request->filled('rombel_id')) {
            $rombelId = (int) $request->query('rombel_id');
            $query->whereHas('rombels', fn ($q) => $q->where('rombels.id', $rombelId));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('updated_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('updated_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $exams = $query->orderByDesc('updated_at')->get()->map(function (Exam $exam) {
            $avgScore = $exam->submissions_avg_score;

            return [
                'id' => $exam->id,
                'name' => $exam->name,
                'subject' => $exam->subject ? ['id' => $exam->subject->id, 'name' => $exam->subject->name] : null,
                'rombels' => $exam->rombels->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
                'question_bank_name' => $exam->questionBank?->name ?? '-',
                'starts_at' => $exam->starts_at?->toIso8601String(),
                'updated_at' => $exam->updated_at?->toIso8601String(),
                'submissions_count' => (int) ($exam->submissions_count ?? 0),
                'average_score' => $avgScore !== null ? round((float) $avgScore, 2) : 0,
            ];
        });

        return [
            'exams' => $exams,
            'subjects' => Subject::forSchool($schoolId)->orderBy('name')->get(['id', 'name']),
            'rombels' => Rombel::forSchool($schoolId)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Mengambil detail rekap nilai siswa, distribusi skor, dan analisis butir soal pada satu ujian.
     *
     * @return array<string, mixed>
     */
    public function getExamRecapDetail(Exam $exam, Request $request): array
    {
        $kkm = (float) $request->query('kkm', self::DEFAULT_KKM);
        $rombelFilter = $request->query('rombel_id');
        $search = trim((string) $request->query('search', ''));

        $exam->load(['subject:id,name', 'rombels:id,name', 'questionBank.questions']);

        $submissions = $this->queryFilteredSubmissions($exam->id, $rombelFilter, $search);
        $scores = $submissions->pluck('score')->map(fn ($s) => (float) $s);
        $totalStudents = $submissions->count();
        $questions = $exam->questionBank?->questions ?? collect();

        return [
            'exam' => [
                'id' => $exam->id,
                'name' => $exam->name,
                'subject_name' => $exam->subject?->name ?? '-',
                'rombels' => $exam->rombels->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
                'date' => ($exam->starts_at ?? $exam->updated_at)?->toDateString(),
            ],
            'summary' => $this->buildScoreSummary($scores, $totalStudents, $kkm),
            'distribution' => $this->buildScoreDistribution($scores),
            'students' => $this->formatStudentSubmissions($submissions, $questions->keyBy('id'), $kkm),
            'question_analysis' => $this->buildQuestionAnalysis($questions, $submissions, $totalStudents),
        ];
    }

    /**
     * Menghapus ujian beserta seluruh data submission siswa terkait.
     */
    public function deleteExamRecap(Exam $exam): void
    {
        ExamSubmission::where('exam_id', $exam->id)->delete();
        $exam->delete();
    }

    /**
     * Mengambil daftar submission siswa yang sudah difilter berdasarkan rombel dan kata kunci pencarian.
     *
     * @return Collection<int, ExamSubmission>
     */
    private function queryFilteredSubmissions(int $examId, mixed $rombelFilter, string $search): Collection
    {
        $query = ExamSubmission::query()
            ->where('exam_id', $examId)
            ->with(['student:id,name,nisn,username,rombel_id', 'student.rombel:id,name', 'answers']);

        if ($rombelFilter) {
            $query->whereHas('student', fn ($q) => $q->where('rombel_id', (int) $rombelFilter));
        }

        if ($search !== '') {
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('nisn', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('score')->get();
    }

    /**
     * Menyusun ringkasan statistik nilai ujian (rata-rata, tertinggi, terendah, kelulusan).
     *
     * @param  Collection<int, float>  $scores
     * @return array<string, mixed>
     */
    private function buildScoreSummary(Collection $scores, int $totalStudents, float $kkm): array
    {
        return [
            'average' => $totalStudents > 0 ? round((float) $scores->avg(), 2) : 0,
            'highest' => $totalStudents > 0 ? round((float) $scores->max(), 2) : 0,
            'lowest' => $totalStudents > 0 ? round((float) $scores->min(), 2) : 0,
            'passed_count' => $scores->filter(fn (float $s) => $s >= $kkm)->count(),
            'total_students' => $totalStudents,
            'kkm' => $kkm,
        ];
    }

    /**
     * Menyusun distribusi rentang skor siswa untuk grafik rekap.
     *
     * @param  Collection<int, float>  $scores
     * @return array<int, array<string, mixed>>
     */
    private function buildScoreDistribution(Collection $scores): array
    {
        return [
            ['label' => '0-20', 'count' => $scores->filter(fn (float $s) => $s <= 20)->count()],
            ['label' => '21-40', 'count' => $scores->filter(fn (float $s) => $s > 20 && $s <= 40)->count()],
            ['label' => '41-60', 'count' => $scores->filter(fn (float $s) => $s > 40 && $s <= 60)->count()],
            ['label' => '61-80', 'count' => $scores->filter(fn (float $s) => $s > 60 && $s <= 80)->count()],
            ['label' => '81-100', 'count' => $scores->filter(fn (float $s) => $s > 80)->count()],
        ];
    }

    /**
     * Memformat daftar submission siswa beserta rincian jawaban per soal.
     *
     * @param  Collection<int, ExamSubmission>  $submissions
     * @param  Collection<int, mixed>  $questionsById
     * @return Collection<int, array<string, mixed>>
     */
    private function formatStudentSubmissions(Collection $submissions, Collection $questionsById, float $kkm): Collection
    {
        return $submissions->map(function (ExamSubmission $sub) use ($kkm, $questionsById) {
            $answersMap = $sub->answers->keyBy('question_id');
            $detailAnswers = $questionsById->map(function ($q) use ($answersMap) {
                $ans = $answersMap->get($q->id);
                $correctKey = $q->content['answer'] ?? ($q->content['answers'] ?? null);

                return [
                    'question_id' => $q->id,
                    'type' => $q->type,
                    'text' => $q->content['text'] ?? '',
                    'student_answer' => $ans?->answer,
                    'correct_key' => is_array($correctKey) ? implode(', ', $correctKey) : (string) $correctKey,
                    'is_correct' => $ans?->is_correct,
                ];
            })->values();

            return [
                'id' => $sub->id,
                'nis' => $sub->student?->nisn ?: ($sub->student?->username ?? '-'),
                'name' => $sub->student?->name ?? 'Siswa',
                'rombel' => $sub->student?->rombel?->name ?? '-',
                'correct_count' => $sub->correct_count,
                'wrong_count' => $sub->wrong_count,
                'blank_count' => $sub->blank_count,
                'score' => round((float) $sub->score, 2),
                'passed' => (float) $sub->score >= $kkm,
                'duration_seconds' => $sub->duration_seconds,
                'answers' => $detailAnswers,
            ];
        })->values();
    }

    /**
     * Menyusun analisis tingkat kesukaran empiris dan pengecoh (distractor) untuk setiap butir soal.
     *
     * @param  Collection<int, mixed>  $questions
     * @param  Collection<int, ExamSubmission>  $submissions
     * @return Collection<int, array<string, mixed>>
     */
    private function buildQuestionAnalysis(Collection $questions, Collection $submissions, int $totalStudents): Collection
    {
        $allAnswers = $submissions->flatMap->answers;

        return $questions->values()->map(function ($q, int $idx) use ($allAnswers, $totalStudents) {
            $qAnswers = $allAnswers->where('question_id', $q->id);
            $correctCount = $qAnswers->where('is_correct', true)->count();
            $percentCorrect = $totalStudents > 0 ? round(($correctCount / $totalStudents) * 100, 1) : 0;

            $empiricalDifficulty = $percentCorrect > self::EASY_THRESHOLD_PERCENT
                ? 'mudah'
                : ($percentCorrect >= self::MEDIUM_THRESHOLD_PERCENT ? 'sedang' : 'sukar');

            $optionCounts = [];
            foreach ($qAnswers as $a) {
                $val = trim((string) ($a->answer ?? ''));
                if ($val !== '') {
                    $optionCounts[$val] = ($optionCounts[$val] ?? 0) + 1;
                }
            }

            $correctKey = strtoupper((string) ($q->content['answer'] ?? ''));
            $distractors = collect($optionCounts)
                ->reject(fn ($_, $key) => strtoupper((string) $key) === $correctKey)
                ->sortDesc();
            $topDistractor = $distractors->keys()->first();

            return [
                'no' => $idx + 1,
                'question_id' => $q->id,
                'type' => $q->type,
                'text' => $q->content['text'] ?? '',
                'correct_count' => $correctCount,
                'total_attempts' => $totalStudents,
                'percent_correct' => $percentCorrect,
                'empirical_difficulty' => $empiricalDifficulty,
                'teacher_difficulty' => $q->difficulty,
                'option_distribution' => $optionCounts,
                'top_distractor' => $topDistractor ? "{$topDistractor} ({$distractors->first()} siswa)" : '-',
            ];
        });
    }
}
