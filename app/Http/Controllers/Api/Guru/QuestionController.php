<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\BulkImportQuestionRequest;
use App\Http\Requests\Guru\ImportQuestionDocxRequest;
use App\Http\Requests\Guru\StoreQuestionRequest;
use App\Http\Requests\Guru\UpdateQuestionRequest;
use App\Http\Requests\Guru\UploadQuestionMediaRequest;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Services\Guru\QuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class QuestionController extends Controller
{
    /**
     * Menginisialisasi controller dengan layanan pengelolaan soal.
     */
    public function __construct(
        private readonly QuestionService $questionService
    ) {}

    /**
     * Menyimpan soal baru ke bank soal yang dipilih atau dibuat langsung.
     */
    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;
        $validated = $request->validated();

        if (! empty($validated['new_bank'])) {
            $bank = QuestionBank::create([
                'school_id' => $schoolId,
                'owner_id' => $user->id,
                'name' => $this->questionService->sanitizeText($validated['new_bank']['name']),
                'subject_id' => $validated['new_bank']['subject_id'],
                'grade' => $this->questionService->sanitizeText($validated['new_bank']['grade']),
                'visibility' => $validated['new_bank']['visibility'] ?? 'private',
            ]);
        } elseif (! empty($validated['bank_id'])) {
            $bank = QuestionBank::forSchool($schoolId)->find($validated['bank_id']);
            if (! $bank) {
                return response()->json(['message' => 'Paket soal tidak ditemukan.'], 404);
            }
            if ((int) $bank->owner_id !== (int) $user->id) {
                return response()->json(['message' => 'Anda hanya dapat menambahkan soal ke paket milik sendiri.'], 403);
            }
            if ($bank->questions()->count() >= 60) {
                return response()->json(['message' => 'Maksimal 60 soal per paket soal.'], 422);
            }
        } else {
            throw ValidationException::withMessages([
                'bank_id' => ['Pilih paket soal tujuan atau buat paket baru.'],
            ]);
        }

        $sanitizedContent = $this->questionService->validateAndSanitizeContent(
            $validated['type'],
            $validated['content']
        );

        $question = Question::create([
            'school_id' => $schoolId,
            'bank_id' => $bank->id,
            'type' => $validated['type'],
            'difficulty' => $validated['difficulty'],
            'content' => $sanitizedContent,
            'weight' => $validated['weight'],
            'explanation' => isset($validated['explanation'])
                ? $this->questionService->sanitizeText($validated['explanation'])
                : null,
            'version' => 1,
        ]);

        return response()->json([
            'message' => 'Soal berhasil disimpan.',
            'question' => $question,
            'bank_id' => $bank->id,
        ], 201);
    }

    /**
     * Menampilkan detail satu soal.
     */
    public function show(Request $request, Question $question): JsonResponse
    {
        $user = $request->user();
        if ((int) $question->school_id !== (int) $user->school_id) {
            return response()->json(['message' => 'Soal tidak ditemukan.'], 404);
        }

        $question->load('bank:id,owner_id,visibility,name');
        $isOwner = (int) $question->bank?->owner_id === (int) $user->id;
        if (! $isOwner && $question->bank?->visibility !== 'school') {
            return response()->json(['message' => 'Anda tidak memiliki akses ke soal ini.'], 403);
        }

        return response()->json(['question' => $question]);
    }

    /**
     * Memperbarui data soal yang sudah ada.
     */
    public function update(UpdateQuestionRequest $request, Question $question): JsonResponse
    {
        $user = $request->user();
        if ((int) $question->school_id !== (int) $user->school_id) {
            return response()->json(['message' => 'Soal tidak ditemukan.'], 404);
        }

        $question->load('bank:id,owner_id');
        if ((int) $question->bank?->owner_id !== (int) $user->id) {
            return response()->json(['message' => 'Hanya pemilik soal yang dapat mengubah soal ini.'], 403);
        }

        if ($this->questionService->isBankLockedByExam($question->bank_id)) {
            return response()->json([
                'message' => 'Soal tidak dapat diubah karena paket soal sedang atau sudah digunakan pada ujian.',
            ], 422);
        }

        $validated = $request->validated();
        $type = $validated['type'] ?? $question->type;
        $content = isset($validated['content'])
            ? $this->questionService->validateAndSanitizeContent($type, $validated['content'])
            : $question->content;

        $question->update([
            'type' => $type,
            'difficulty' => $validated['difficulty'] ?? $question->difficulty,
            'weight' => $validated['weight'] ?? $question->weight,
            'explanation' => array_key_exists('explanation', $validated)
                ? ($validated['explanation'] !== null ? $this->questionService->sanitizeText($validated['explanation']) : null)
                : $question->explanation,
            'content' => $content,
            'version' => $question->version + 1,
        ]);

        return response()->json([
            'message' => 'Soal berhasil diperbarui.',
            'question' => $question,
        ]);
    }

    /**
     * Menghapus soal dari bank soal.
     */
    public function destroy(Request $request, Question $question): JsonResponse
    {
        $user = $request->user();
        if ((int) $question->school_id !== (int) $user->school_id) {
            return response()->json(['message' => 'Soal tidak ditemukan.'], 404);
        }

        $question->load('bank:id,owner_id');
        if ((int) $question->bank?->owner_id !== (int) $user->id) {
            return response()->json(['message' => 'Hanya pemilik soal yang dapat menghapus soal ini.'], 403);
        }

        if ($this->questionService->isBankLockedByExam($question->bank_id)) {
            return response()->json([
                'message' => 'Soal tidak dapat dihapus karena paket soal sedang atau sudah digunakan pada ujian.',
            ], 422);
        }

        $question->delete();

        return response()->json(['message' => 'Soal berhasil dihapus.']);
    }

    /**
     * Mengunggah media gambar untuk disisipkan ke dalam soal.
     */
    public function uploadMedia(UploadQuestionMediaRequest $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $media = $this->questionService->uploadMedia(
            $request->file('file'),
            $schoolId,
            $request->input('alt_text')
        );

        return response()->json([
            'media_id' => $media->id,
            'path' => $media->file,
            'url' => Storage::disk('public')->url($media->file),
            'alt_text' => $media->alt_text,
        ], 201);
    }

    /**
     * Mengimpor banyak soal sekaligus dari baris data terstruktur.
     */
    public function bulkImport(BulkImportQuestionRequest $request, QuestionBank $questionBank): JsonResponse
    {
        if ($errorResponse = $this->authorizeBankImport($request, $questionBank)) {
            return $errorResponse;
        }

        $result = $this->questionService->bulkImportFromRows(
            $questionBank,
            (int) $request->user()->school_id,
            $request->validated()['rows']
        );

        return response()->json([
            'message' => "{$result['imported_count']} soal berhasil diimpor.",
            'imported_count' => $result['imported_count'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Mengunduh template dokumen DOCX untuk impor soal.
     */
    public function downloadDocxTemplate()
    {
        $staticTemplate = storage_path('app/templates/Template_Import_Soal_BinoExam.docx');
        if (file_exists($staticTemplate)) {
            return response()->download($staticTemplate, 'Template_Import_Soal_BinoExam.docx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
        }

        $tmpPath = $this->questionService->buildDocxTemplatePath();

        return response()->download($tmpPath, 'Template_Import_Soal_BinoExam.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Mengimpor soal dari file dokumen DOCX ke bank soal.
     */
    public function importDocx(ImportQuestionDocxRequest $request, QuestionBank $questionBank): JsonResponse
    {
        if ($errorResponse = $this->authorizeBankImport($request, $questionBank)) {
            return $errorResponse;
        }

        $result = $this->questionService->importFromDocx(
            $questionBank,
            (int) $request->user()->school_id,
            $request->file('file')->getRealPath()
        );

        return response()->json([
            'message' => "{$result['imported_count']} soal dari Word berhasil diimpor.",
            'imported_count' => $result['imported_count'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Memeriksa otorisasi guru dan status kunci bank soal sebelum impor.
     */
    private function authorizeBankImport(Request $request, QuestionBank $questionBank): ?JsonResponse
    {
        $user = $request->user();
        if ((int) $questionBank->school_id !== (int) $user->school_id) {
            return response()->json(['message' => 'Paket soal tidak ditemukan.'], 404);
        }

        if ((int) $questionBank->owner_id !== (int) $user->id) {
            return response()->json(['message' => 'Hanya pemilik paket yang dapat mengimpor soal.'], 403);
        }

        if ($questionBank->questions()->count() >= 60) {
            return response()->json(['message' => 'Maksimal 60 soal per paket soal.'], 422);
        }

        return null;
    }
}
