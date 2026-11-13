<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamPackage;
use App\Models\QuestionBank;
use App\Models\Rombel;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    /**
     * Menampilkan daftar ujian milik guru beserta opsi bank soal, rombel, dan mapel.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        $query = Exam::query()
            ->forSchool($schoolId)
            ->where('teacher_id', $user->id)
            ->with([
                'subject:id,name,code',
                'questionBank:id,name',
                'rombels:id,name',
                'package:id,exam_id,version,status,sha256',
            ]);

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->query('subject_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $exams = $query->orderByDesc('updated_at')->get()->map(fn (Exam $exam) => $this->formatExam($exam));

        // Ambil mapel & rombel sesuai hak akses guru (jika belum ada penugasan, fallback ke sekolah)
        $assignments = TeacherAssignment::query()
            ->where('school_id', $schoolId)
            ->where('teacher_id', $user->id)
            ->get();

        $assignedSubjectIds = $assignments->pluck('subject_id')->unique()->filter()->values();
        $assignedRombelIds = $assignments->pluck('rombel_id')->unique()->filter()->values();

        $subjects = Subject::forSchool($schoolId)
            ->when($assignedSubjectIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $assignedSubjectIds))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $rombels = Rombel::forSchool($schoolId)
            ->when($assignedRombelIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $assignedRombelIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        $questionBanks = QuestionBank::forSchool($schoolId)
            ->where('owner_id', $user->id)
            ->with(['subject:id,name'])
            ->withCount('questions')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'subject_id' => $b->subject_id,
                'subject_name' => $b->subject?->name ?? '-',
                'grade' => $b->grade,
                'questions_count' => (int) $b->questions_count,
            ]);

        return response()->json([
            'exams' => $exams,
            'subjects' => $subjects,
            'rombels' => $rombels,
            'question_banks' => $questionBanks,
        ]);
    }

    /**
     * Membuat ujian baru untuk guru.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'rombel_ids' => ['required', 'array', 'min:1'],
            'rombel_ids.*' => ['integer', Rule::exists('rombels', 'id')->where('school_id', $schoolId)],
            'question_bank_id' => ['required', 'integer', Rule::exists('question_banks', 'id')->where('school_id', $schoolId)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'shuffle_questions' => ['boolean'],
            'shuffle_options' => ['boolean'],
            'action' => ['nullable', Rule::in(['draft', 'publish'])],
        ], [
            'starts_at.required' => 'Waktu mulai wajib diisi.',
            'starts_at.date' => 'Waktu mulai harus berupa format tanggal dan waktu yang valid.',
            'ends_at.required' => 'Waktu selesai wajib diisi.',
            'ends_at.date' => 'Waktu selesai harus berupa format tanggal dan waktu yang valid.',
            'ends_at.after' => 'Waktu selesai harus lebih lambat dari waktu mulai.',
        ]);

        $action = $validated['action'] ?? 'draft';
        $durationMinutes = (int) ($validated['duration_minutes'] ?? 60);

        if (! empty($validated['starts_at']) && ! empty($validated['ends_at'])) {
            $startTime = \Illuminate\Support\Carbon::parse($validated['starts_at']);
            $endTime = \Illuminate\Support\Carbon::parse($validated['ends_at']);
            if ($startTime->diffInMinutes($endTime, false) < $durationMinutes) {
                return response()->json([
                    'message' => "Rentang waktu mulai hingga waktu selesai minimal {$durationMinutes} menit sesuai durasi ujian.",
                    'errors' => [
                        'ends_at' => ["Rentang waktu mulai hingga waktu selesai minimal {$durationMinutes} menit sesuai durasi ujian."],
                    ],
                ], 422);
            }
        }
        $bank = QuestionBank::forSchool($schoolId)->withCount('questions')->findOrFail($validated['question_bank_id']);

        if ($action === 'publish' && (int) $bank->questions_count === 0) {
            return response()->json([
                'message' => 'Paket soal yang dipilih belum memiliki butir soal. Tambahkan soal terlebih dahulu sebelum mempublikasikan ujian.',
            ], 422);
        }

        $exam = Exam::create([
            'school_id' => $schoolId,
            'teacher_id' => $user->id,
            'subject_id' => $validated['subject_id'],
            'question_bank_id' => $validated['question_bank_id'],
            'name' => trim($validated['name']),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'duration_minutes' => $validated['duration_minutes'] ?? 60,
            'token' => $this->generateUniqueToken(),
            'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
            'shuffle_options' => (bool) ($validated['shuffle_options'] ?? false),
            'status' => $action === 'publish' ? 'scheduled' : 'draft',
        ]);

        $exam->rombels()->sync($validated['rombel_ids']);

        if ($action === 'publish') {
            $this->generateStudentPackage($exam);
        }

        $exam->load(['subject:id,name,code', 'questionBank:id,name', 'rombels:id,name', 'package']);

        return response()->json([
            'message' => $action === 'publish' ? 'Ujian berhasil dipublikasikan dan paket soal siap diunduh.' : 'Ujian berhasil disimpan sebagai draf.',
            'exam' => $this->formatExam($exam),
        ], 201);
    }

    /**
     * Menampilkan detail ujian tertentu milik guru.
     */
    public function show(Request $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        if ((int) $exam->school_id !== (int) $user->school_id || (int) $exam->teacher_id !== (int) $user->id) {
            return response()->json(['message' => 'Ujian tidak ditemukan.'], 404);
        }

        $exam->load(['subject:id,name,code', 'questionBank:id,name', 'rombels:id,name', 'package']);

        return response()->json([
            'exam' => $this->formatExam($exam),
        ]);
    }

    /**
     * Memperbarui data ujian yang masih berstatus draf.
     */
    public function update(Request $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        if ((int) $exam->school_id !== $schoolId || (int) $exam->teacher_id !== (int) $user->id) {
            return response()->json(['message' => 'Ujian tidak ditemukan.'], 404);
        }

        if (in_array($exam->status, ['ongoing', 'finished'], true)) {
            return response()->json(['message' => 'Ujian yang sedang berlangsung atau sudah selesai tidak dapat diubah.'], 422);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'rombel_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'rombel_ids.*' => ['integer', Rule::exists('rombels', 'id')->where('school_id', $schoolId)],
            'question_bank_id' => ['sometimes', 'required', 'integer', Rule::exists('question_banks', 'id')->where('school_id', $schoolId)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'shuffle_questions' => ['boolean'],
            'shuffle_options' => ['boolean'],
            'action' => ['nullable', Rule::in(['draft', 'publish'])],
        ], [
            'starts_at.required' => 'Waktu mulai wajib diisi.',
            'starts_at.date' => 'Waktu mulai harus berupa format tanggal dan waktu yang valid.',
            'ends_at.required' => 'Waktu selesai wajib diisi.',
            'ends_at.date' => 'Waktu selesai harus berupa format tanggal dan waktu yang valid.',
            'ends_at.after' => 'Waktu selesai harus lebih lambat dari waktu mulai.',
        ]);

        $action = $validated['action'] ?? ($exam->status === 'scheduled' ? 'publish' : 'draft');
        $effectiveStart = array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $exam->starts_at;
        $effectiveEnd = array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $exam->ends_at;
        $effectiveDuration = (int) ($validated['duration_minutes'] ?? $exam->duration_minutes);

        if (! empty($effectiveStart) && ! empty($effectiveEnd)) {
            $startTime = \Illuminate\Support\Carbon::parse($effectiveStart);
            $endTime = \Illuminate\Support\Carbon::parse($effectiveEnd);
            if ($startTime->diffInMinutes($endTime, false) < $effectiveDuration) {
                return response()->json([
                    'message' => "Rentang waktu mulai hingga waktu selesai minimal {$effectiveDuration} menit sesuai durasi ujian.",
                    'errors' => [
                        'ends_at' => ["Rentang waktu mulai hingga waktu selesai minimal {$effectiveDuration} menit sesuai durasi ujian."],
                    ],
                ], 422);
            }
        }

        $exam->update([
            'name' => isset($validated['name']) ? trim($validated['name']) : $exam->name,
            'subject_id' => $validated['subject_id'] ?? $exam->subject_id,
            'question_bank_id' => $validated['question_bank_id'] ?? $exam->question_bank_id,
            'starts_at' => array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $exam->starts_at,
            'ends_at' => array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $exam->ends_at,
            'duration_minutes' => $validated['duration_minutes'] ?? $exam->duration_minutes,
            'shuffle_questions' => $validated['shuffle_questions'] ?? $exam->shuffle_questions,
            'shuffle_options' => $validated['shuffle_options'] ?? $exam->shuffle_options,
            'status' => $action === 'publish' ? 'scheduled' : 'draft',
        ]);

        if (isset($validated['rombel_ids'])) {
            $exam->rombels()->sync($validated['rombel_ids']);
        }

        if ($action === 'publish') {
            $this->generateStudentPackage($exam);
        }

        $exam->load(['subject:id,name,code', 'questionBank:id,name', 'rombels:id,name', 'package']);

        return response()->json([
            'message' => 'Ujian berhasil diperbarui.',
            'exam' => $this->formatExam($exam),
        ]);
    }

    /**
     * Menghapus ujian milik guru.
     */
    public function destroy(Request $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        if ((int) $exam->school_id !== (int) $user->school_id || (int) $exam->teacher_id !== (int) $user->id) {
            return response()->json(['message' => 'Ujian tidak ditemukan.'], 404);
        }

        if ($exam->status === 'ongoing') {
            return response()->json(['message' => 'Ujian yang sedang berlangsung tidak dapat dihapus.'], 422);
        }

        $exam->delete();

        return response()->json(['message' => 'Ujian berhasil dihapus.']);
    }

    /**
     * Memulai ujian dan membuat paket soal untuk siswa.
     */
    public function start(Request $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        if ((int) $exam->school_id !== (int) $user->school_id || (int) $exam->teacher_id !== (int) $user->id) {
            return response()->json(['message' => 'Ujian tidak ditemukan.'], 404);
        }

        if (! in_array($exam->status, ['draft', 'scheduled'], true)) {
            return response()->json(['message' => 'Ujian tidak dapat dimulai dari status saat ini.'], 422);
        }

        if ($exam->ends_at && now()->greaterThanOrEqualTo($exam->ends_at)) {
            return response()->json([
                'message' => 'Ujian tidak dapat dimulai karena waktu selesai sudah terlewat. Silakan perbarui jadwal terlebih dahulu.',
            ], 422);
        }

        $bank = $exam->questionBank()->withCount('questions')->first();
        if (! $bank || (int) $bank->questions_count === 0) {
            return response()->json(['message' => 'Paket soal belum memiliki butir soal.'], 422);
        }

        if (! $exam->package) {
            $this->generateStudentPackage($exam);
        }

        $exam->update(['status' => 'ongoing']);
        $exam->load(['subject:id,name,code', 'questionBank:id,name', 'rombels:id,name', 'package']);

        return response()->json([
            'message' => 'Ujian telah dimulai.',
            'exam' => $this->formatExam($exam),
        ]);
    }

    /**
     * Mengakhiri ujian yang sedang berlangsung.
     */
    public function finish(Request $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        if ((int) $exam->school_id !== (int) $user->school_id || (int) $exam->teacher_id !== (int) $user->id) {
            return response()->json(['message' => 'Ujian tidak ditemukan.'], 404);
        }

        if ($exam->status !== 'ongoing') {
            return response()->json(['message' => 'Hanya ujian yang sedang berlangsung yang dapat diakhiri.'], 422);
        }

        $exam->update(['status' => 'finished']);
        $exam->load(['subject:id,name,code', 'questionBank:id,name', 'rombels:id,name', 'package']);

        return response()->json([
            'message' => 'Ujian telah diakhiri.',
            'exam' => $this->formatExam($exam),
        ]);
    }

    /**
     * Membuat paket soal terenkripsi untuk siswa tanpa menyertakan kunci jawaban.
     */
    private function generateStudentPackage(Exam $exam): ExamPackage
    {
        $questions = $exam->questionBank()
            ->first()
            ->questions()
            ->orderBy('id')
            ->get()
            ->map(function ($q) {
                $content = $q->content ?? [];
                // Pastikan KUNCI JAWABAN & PEMBAHASAN tidak pernah masuk ke paket siswa
                unset($content['answer'], $content['answers']);
                if (isset($content['pairs']) && is_array($content['pairs'])) {
                    $content['left_items'] = array_column($content['pairs'], 'left');
                    $rightItems = array_column($content['pairs'], 'right');
                    shuffle($rightItems);
                    $content['right_items'] = $rightItems;
                    unset($content['pairs']);
                }

                return [
                    'id' => $q->id,
                    'type' => $q->type,
                    'weight' => $q->weight,
                    'content' => $content,
                ];
            })
            ->values()
            ->all();

        $latestVersion = (int) ($exam->package()->value('version') ?? 0) + 1;
        $payload = json_encode([
            'exam_id' => $exam->id,
            'name' => $exam->name,
            'duration_minutes' => $exam->duration_minutes,
            'shuffle_questions' => $exam->shuffle_questions,
            'shuffle_options' => $exam->shuffle_options,
            'version' => $latestVersion,
            'questions' => $questions,
        ], JSON_UNESCAPED_UNICODE);

        $filePath = "exam-packages/exam_{$exam->id}_v{$latestVersion}.json";
        Storage::disk('local')->put($filePath, $payload);

        return ExamPackage::create([
            'exam_id' => $exam->id,
            'file_path' => $filePath,
            'sha256' => hash('sha256', $payload),
            'version' => $latestVersion,
            'status' => 'ready',
        ]);
    }

    /**
     * Menghasilkan token ujian unik sepanjang 6 karakter.
     */
    private function generateUniqueToken(): string
    {
        do {
            $token = strtoupper(Str::random(6));
        } while (Exam::where('token', $token)->exists());

        return $token;
    }

    /**
     * Memformat data ujian untuk respons API.
     */
    private function formatExam(Exam $exam): array
    {
        $questionsCount = $exam->questionBank ? $exam->questionBank->questions()->count() : 0;

        return [
            'id' => $exam->id,
            'name' => $exam->name,
            'subject_id' => $exam->subject_id,
            'subject' => $exam->subject ? [
                'id' => $exam->subject->id,
                'name' => $exam->subject->name,
                'code' => $exam->subject->code,
            ] : null,
            'question_bank_id' => $exam->question_bank_id,
            'question_bank_name' => $exam->questionBank?->name ?? '-',
            'questions_count' => $questionsCount,
            'rombels' => $exam->rombels->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
            'starts_at' => $exam->starts_at?->toIso8601String(),
            'ends_at' => $exam->ends_at?->toIso8601String(),
            'duration_minutes' => $exam->duration_minutes,
            'token' => $exam->token,
            'shuffle_questions' => $exam->shuffle_questions,
            'shuffle_options' => $exam->shuffle_options,
            'status' => $exam->status,
            'package' => $exam->package ? [
                'version' => $exam->package->version,
                'status' => $exam->package->status,
            ] : null,
            'updated_at' => $exam->updated_at?->toIso8601String(),
        ];
    }
}
