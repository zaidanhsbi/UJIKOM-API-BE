<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicYearController extends Controller
{
    /**
     * Menampilkan daftar tahun ajaran milik sekolah.
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $years = AcademicYear::forSchool($schoolId)
            ->orderByDesc('is_active')
            ->orderByDesc('name')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $years,
        ]);
    }

    /**
     * Membuat aturan validasi format nama tahun ajaran.
     */
    private function academicYearNameRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $trimmed = trim((string) $value);
            if (! preg_match('/^(\d{4})\/(\d{4})$/', $trimmed, $matches)) {
                $fail('Format tahun ajaran harus XXXX/YYYY (contoh: 2026/2027).');
                return;
            }
            if ((int) $matches[2] !== ((int) $matches[1] + 1)) {
                $fail('Tahun kedua harus tepat 1 tahun setelah tahun pertama (contoh: 2026/2027).');
            }
        };
    }

    /**
     * Menyimpan tahun ajaran baru untuk sekolah.
     */
    public function store(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:20',
                $this->academicYearNameRule(),
                Rule::unique('academic_years', 'name')
                    ->where(fn ($q) => $q->where('school_id', $schoolId)->where('semester', $request->input('semester', 'Ganjil'))),
            ],
            'semester' => ['required', Rule::in(['Ganjil', 'Genap'])],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Tahun ajaran wajib diisi.',
            'name.unique' => 'Kombinasi tahun ajaran dan semester sudah ada.',
            'semester.in' => 'Semester harus Ganjil atau Genap.',
        ]);

        $year = DB::transaction(function () use ($schoolId, $validated) {
            $isActive = (bool) ($validated['is_active'] ?? false);
            if ($isActive) {
                AcademicYear::forSchool($schoolId)->update(['is_active' => false]);
            }

            return AcademicYear::create([
                'school_id' => $schoolId,
                'name' => trim($validated['name']),
                'semester' => $validated['semester'],
                'is_active' => $isActive,
            ]);
        });

        return response()->json([
            'message' => 'Tahun ajaran berhasil ditambahkan.',
            'data' => $year,
        ], 201);
    }

    /**
     * Memperbarui data tahun ajaran yang sudah ada.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $year = AcademicYear::forSchool($schoolId)->findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:20',
                $this->academicYearNameRule(),
                Rule::unique('academic_years', 'name')
                    ->ignore($year->id)
                    ->where(fn ($q) => $q->where('school_id', $schoolId)->where('semester', $request->input('semester', $year->semester))),
            ],
            'semester' => ['required', Rule::in(['Ganjil', 'Genap'])],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Tahun ajaran wajib diisi.',
            'name.unique' => 'Kombinasi tahun ajaran dan semester sudah ada.',
            'semester.in' => 'Semester harus Ganjil atau Genap.',
        ]);

        DB::transaction(function () use ($schoolId, $year, $validated) {
            $isActive = array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : $year->is_active;

            if ($isActive) {
                AcademicYear::forSchool($schoolId)
                    ->where('id', '!=', $year->id)
                    ->update(['is_active' => false]);
            }

            $year->update([
                'name' => trim($validated['name']),
                'semester' => $validated['semester'],
                'is_active' => $isActive,
            ]);
        });

        return response()->json([
            'message' => 'Tahun ajaran berhasil diperbarui.',
            'data' => $year->fresh(),
        ]);
    }

    /**
     * Menetapkan tahun ajaran tertentu sebagai tahun ajaran aktif.
     */
    public function setActive(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $year = AcademicYear::forSchool($schoolId)->findOrFail($id);

        DB::transaction(function () use ($schoolId, $year) {
            AcademicYear::forSchool($schoolId)->update(['is_active' => false]);
            $year->update(['is_active' => true]);
        });

        return response()->json([
            'message' => "Tahun ajaran {$year->name} ({$year->semester}) berhasil diaktifkan.",
            'data' => $year->fresh(),
        ]);
    }

    /**
     * Menghapus tahun ajaran jika belum digunakan oleh rombel atau siswa.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $year = AcademicYear::forSchool($schoolId)->findOrFail($id);

        if ($year->is_active) {
            return response()->json([
                'message' => 'Tahun ajaran yang sedang aktif tidak dapat dihapus.',
            ], 422);
        }

        $year->delete();

        return response()->json([
            'message' => 'Tahun ajaran berhasil dihapus.',
        ]);
    }
}
