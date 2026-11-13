<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\QuestionBank;
use App\Models\Rombel;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class QuestionBankController extends Controller
{
    /**
     * Menampilkan daftar bank soal yang dapat diakses guru.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;
        $tab = $request->query('tab', 'private');

        $query = QuestionBank::query()
            ->forSchool($schoolId)
            ->with(['subject:id,name,code', 'owner:id,name'])
            ->withCount('questions');

        if ($tab === 'school') {
            $query->where('visibility', 'school')
                ->where('owner_id', '!=', $user->id);
        } else {
            $query->where('owner_id', $user->id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->query('subject_id'));
        }

        if ($request->filled('grade')) {
            $query->where('grade', $request->query('grade'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $banks = $query->orderByDesc('updated_at')->get()->map(fn ($bank) => $this->formatBank($bank, $user->id));

        $subjects = Subject::forSchool($schoolId)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $rombels = Rombel::forSchool($schoolId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'question_banks' => $banks,
            'subjects' => $subjects,
            'rombels' => $rombels,
        ]);
    }

    /**
     * Membuat bank soal baru milik guru.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject_id' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id')->where('school_id', $schoolId),
            ],
            'grade' => ['required', 'string', 'max:50'],
            'visibility' => ['required', Rule::in(['private', 'school'])],
        ]);

        $bank = QuestionBank::create([
            'school_id' => $schoolId,
            'owner_id' => $user->id,
            'name' => $validated['name'],
            'subject_id' => $validated['subject_id'],
            'grade' => $validated['grade'],
            'visibility' => $validated['visibility'],
        ]);

        $bank->load(['subject:id,name,code', 'owner:id,name']);

        return response()->json([
            'message' => 'Paket soal berhasil dibuat.',
            'question_bank' => $this->formatBank($bank, $user->id),
        ], 201);
    }

    /**
     * Menampilkan detail bank soal beserta daftar soalnya.
     */
    public function show(Request $request, QuestionBank $questionBank): JsonResponse
    {
        $user = $request->user();

        if ((int) $questionBank->school_id !== (int) $user->school_id) {
            return response()->json(['message' => 'Paket soal tidak ditemukan.'], 404);
        }

        $isOwner = (int) $questionBank->owner_id === (int) $user->id;
        if (! $isOwner && $questionBank->visibility !== 'school') {
            return response()->json(['message' => 'Anda tidak memiliki akses ke paket soal ini.'], 403);
        }

        $questionBank->load(['subject:id,name,code', 'owner:id,name'])
            ->loadCount('questions');

        $questions = $questionBank->questions()
            ->orderBy('id')
            ->get(['id', 'bank_id', 'type', 'difficulty', 'content', 'weight', 'explanation', 'version', 'created_at']);

        return response()->json([
            'question_bank' => $this->formatBank($questionBank, $user->id),
            'questions' => $questions,
        ]);
    }

    /**
     * Memperbarui metadata bank soal milik guru.
     */
    public function update(Request $request, QuestionBank $questionBank): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        if ((int) $questionBank->school_id !== $schoolId) {
            return response()->json(['message' => 'Paket soal tidak ditemukan.'], 404);
        }

        if ((int) $questionBank->owner_id !== (int) $user->id) {
            return response()->json(['message' => 'Hanya pemilik paket yang dapat mengubah paket ini.'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'subject_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('subjects', 'id')->where('school_id', $schoolId),
            ],
            'grade' => ['sometimes', 'required', 'string', 'max:50'],
            'visibility' => ['sometimes', 'required', Rule::in(['private', 'school'])],
        ]);

        $questionBank->update($validated);
        $questionBank->load(['subject:id,name,code', 'owner:id,name']);

        return response()->json([
            'message' => 'Paket soal berhasil diperbarui.',
            'question_bank' => $this->formatBank($questionBank, $user->id),
        ]);
    }

    /**
     * Menghapus bank soal milik guru jika belum dipakai ujian.
     */
    public function destroy(Request $request, QuestionBank $questionBank): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        if ((int) $questionBank->school_id !== $schoolId) {
            return response()->json(['message' => 'Paket soal tidak ditemukan.'], 404);
        }

        if ((int) $questionBank->owner_id !== (int) $user->id) {
            return response()->json(['message' => 'Hanya pemilik paket yang dapat menghapus paket ini.'], 403);
        }

        if (Schema::hasTable('exams') && Schema::hasColumn('exams', 'question_bank_id')) {
            $hasLinkedExam = DB::table('exams')
                ->where('question_bank_id', $questionBank->id)
                ->whereIn('status', ['active', 'aktif', 'finished', 'selesai'])
                ->exists();

            if ($hasLinkedExam) {
                return response()->json([
                    'message' => 'Paket soal tidak dapat dihapus karena sedang digunakan pada jadwal ujian aktif atau selesai.',
                ], 422);
            }
        }

        $questionBank->delete();

        return response()->json([
            'message' => 'Paket soal berhasil dihapus.',
        ]);
    }

    /**
     * Menduplikasi bank soal yang tersedia ke koleksi pribadi guru.
     */
    public function duplicate(Request $request, QuestionBank $questionBank): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        if ((int) $questionBank->school_id !== $schoolId) {
            return response()->json(['message' => 'Paket soal tidak ditemukan.'], 404);
        }

        if ($questionBank->visibility !== 'school' && (int) $questionBank->owner_id !== (int) $user->id) {
            return response()->json(['message' => 'Paket soal ini tidak dibagikan ke sekolah.'], 403);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $copyName = ! empty($validated['name'])
            ? trim($validated['name'])
            : 'Salinan - ' . $questionBank->name;

        $copy = QuestionBank::create([
            'school_id' => $schoolId,
            'owner_id' => $user->id,
            'name' => $copyName,
            'subject_id' => $questionBank->subject_id,
            'grade' => $questionBank->grade,
            'visibility' => 'private',
            'source_id' => $questionBank->id,
        ]);

        $copy->load(['subject:id,name,code', 'owner:id,name']);

        return response()->json([
            'message' => 'Paket soal berhasil disalin ke Bank Saya.',
            'question_bank' => $this->formatBank($copy, $user->id),
        ], 201);
    }

    /**
     * Memformat data bank soal untuk respons API.
     */
    private function formatBank(QuestionBank $bank, int $currentUserId): array
    {
        return [
            'id' => $bank->id,
            'name' => $bank->name,
            'subject_id' => $bank->subject_id,
            'subject' => $bank->subject ? [
                'id' => $bank->subject->id,
                'name' => $bank->subject->name,
                'code' => $bank->subject->code,
            ] : null,
            'grade' => $bank->grade,
            'visibility' => $bank->visibility,
            'source_id' => $bank->source_id,
            'owner_id' => $bank->owner_id,
            'owner_name' => $bank->owner?->name ?? 'Guru',
            'is_owner' => (int) $bank->owner_id === $currentUserId,
            'questions_count' => (int) ($bank->questions_count ?? 0),
            'updated_at' => $bank->updated_at?->toIso8601String(),
        ];
    }
}
