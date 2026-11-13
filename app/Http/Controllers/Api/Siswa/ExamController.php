<?php

namespace App\Http\Controllers\Api\Siswa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Siswa\StartExamRequest;
use App\Http\Requests\Siswa\SubmitExamRequest;
use App\Models\Exam;
use App\Services\Siswa\StudentExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    /**
     * Menginisialisasi controller dengan layanan ujian siswa.
     */
    public function __construct(
        private readonly StudentExamService $studentExamService
    ) {}

    /**
     * Menampilkan daftar ujian yang dapat diikuti oleh siswa yang sedang login.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'siswa') {
            return response()->json(['message' => 'Akses khusus siswa.'], 403);
        }

        $exams = $this->studentExamService->listAvailableExams($user);

        return response()->json(['exams' => $exams]);
    }

    /**
     * Memvalidasi token dan memulai atau melanjutkan sesi pengerjaan ujian siswa.
     */
    public function start(StartExamRequest $request, Exam $exam): JsonResponse
    {
        $user = $request->user();

        if ((int) $exam->school_id !== (int) $user->school_id) {
            return response()->json(['message' => 'Ujian tidak ditemukan.'], 404);
        }

        if ($user->rombel_id && ! $exam->rombels()->where('rombels.id', $user->rombel_id)->exists()) {
            return response()->json(['message' => 'Anda tidak terdaftar pada ujian ini.'], 403);
        }

        if ($exam->status !== Exam::STATUS_ONGOING) {
            return response()->json(['message' => 'Ujian belum dimulai atau sudah berakhir.'], 422);
        }

        if ($exam->ends_at && now()->greaterThanOrEqualTo($exam->ends_at)) {
            return response()->json(['message' => 'Waktu ujian sudah berakhir.'], 422);
        }

        $validated = $request->validated();
        if (strtoupper(trim($validated['token'])) !== strtoupper((string) $exam->token)) {
            return response()->json(['message' => 'Token ujian tidak valid.'], 422);
        }

        $session = $this->studentExamService->startExamSession($exam, $user);
        if ($session['already_finished']) {
            return response()->json(['message' => 'Anda sudah mengumpulkan ujian ini.'], 422);
        }

        return response()->json($session['payload']);
    }

    /**
     * Menerima pengumpulan jawaban ujian dari siswa dan mengembalikan hasil penilaian.
     */
    public function submit(SubmitExamRequest $request, Exam $exam): JsonResponse
    {
        $user = $request->user();

        if ((int) $exam->school_id !== (int) $user->school_id) {
            return response()->json(['message' => 'Ujian tidak ditemukan.'], 404);
        }

        $result = $this->studentExamService->submitExamAnswers(
            $exam,
            $user,
            $request->validated()
        );

        return response()->json([
            'message' => 'Jawaban ujian berhasil dikumpulkan.',
            'submission_id' => $result->id,
            'score' => $result->score,
        ]);
    }
}
