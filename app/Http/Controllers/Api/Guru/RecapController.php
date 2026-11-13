<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\Guru\RecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecapController extends Controller
{
    /**
     * Menginisialisasi controller dengan layanan rekapitulasi nilai.
     */
    public function __construct(
        private readonly RecapService $recapService
    ) {}

    /**
     * Menampilkan daftar ujian milik guru yang siap direkap beserta ringkasan rata-rata nilai.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->recapService->getRecapList(
            $request,
            (int) $user->school_id,
            (int) $user->id
        );

        return response()->json($data);
    }

    /**
     * Menampilkan detail rekap nilai siswa dan analisis soal pada satu ujian.
     */
    public function show(Request $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        if (! $this->ownsExam($exam, (int) $user->school_id, (int) $user->id)) {
            return response()->json(['message' => 'Rekap ujian tidak ditemukan.'], 404);
        }

        return response()->json($this->recapService->getExamRecapDetail($exam, $request));
    }

    /**
     * Menghapus data rekap ujian beserta seluruh submission siswa di dalamnya.
     */
    public function destroy(Request $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        if (! $this->ownsExam($exam, (int) $user->school_id, (int) $user->id)) {
            return response()->json(['message' => 'Rekap ujian tidak ditemukan.'], 404);
        }

        $this->recapService->deleteExamRecap($exam);

        return response()->json([
            'message' => 'Rekap ujian berhasil dihapus.',
        ]);
    }

    /**
     * Memeriksa apakah ujian milik sekolah dan guru yang sedang mengakses.
     */
    private function ownsExam(Exam $exam, int $schoolId, int $teacherId): bool
    {
        return (int) $exam->school_id === $schoolId && (int) $exam->teacher_id === $teacherId;
    }
}
