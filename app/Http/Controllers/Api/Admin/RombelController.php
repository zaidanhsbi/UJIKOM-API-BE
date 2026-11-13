<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RombelRequest;
use App\Models\AcademicYear;
use App\Models\Rombel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RombelController extends Controller
{
    /**
     * GET /api/admin/rombels
     * Ambil daftar rombel/kelas ter-scope school_id beserta jumlah siswa.
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 0);

        $query = Rombel::forSchool($schoolId)->with('academicYear')->withCount('students');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        $query->orderBy('name');

        if ($perPage > 0) {
            $paginator = $query->paginate(min($perPage, 100));

            return response()->json([
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    /**
     * POST /api/admin/rombels
     * Tambah rombel/kelas baru.
     */
    private function resolveAcademicYear(int $schoolId, mixed $academicYearId): ?AcademicYear
    {
        if ($academicYearId) {
            $found = AcademicYear::forSchool($schoolId)->find((int) $academicYearId);
            if ($found) {
                return $found;
            }
        }

        return AcademicYear::forSchool($schoolId)->where('is_active', true)->first();
    }

    /**
     * Mencari atau membuat tahun ajaran berdasarkan nama.
     */
    private function resolveAcademicYearByName(
        int $schoolId,
        string $yearName,
        ?AcademicYear $fallback,
        array &$cache
    ): ?AcademicYear {
        $cleanName = trim($yearName);
        if ($cleanName === '') {
            return $fallback;
        }

        $key = strtoupper($cleanName);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $found = AcademicYear::forSchool($schoolId)
            ->where('name', $cleanName)
            ->orderByDesc('is_active')
            ->first();

        if (! $found) {
            $found = AcademicYear::create([
                'school_id' => $schoolId,
                'name' => $cleanName,
                'semester' => 'Ganjil',
                'is_active' => false,
            ]);
        }

        $cache[$key] = $found;
        return $found;
    }

    /**
     * Menyimpan data rombel baru ke sekolah.
     */
    public function store(RombelRequest $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $academicYear = $this->resolveAcademicYear($schoolId, $request->input('academic_year_id'));

        $rombel = Rombel::create([
            'school_id' => $schoolId,
            'name' => trim((string) $request->input('name')),
            'academic_year_id' => $academicYear?->id,
            'academic_year' => $academicYear?->name ?? (trim((string) $request->input('academic_year', '2026/2027')) ?: '2026/2027'),
        ]);

        $rombel->load('academicYear')->loadCount('students');

        return response()->json([
            'message' => 'Kelas / Rombel berhasil ditambahkan.',
            'data' => $rombel,
        ], 201);
    }

    /**
     * POST /api/admin/rombels/bulk-import
     * Impor massal kelas/rombel dengan rincian per baris (berhasil, dilewati, error).
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $items = $request->input('rombels');

        if (! is_array($items) || count($items) === 0) {
            return response()->json([
                'message' => 'Data impor kelas/rombel kosong atau tidak valid.',
                'errors' => ['rombels' => ['Tidak ada baris data kelas/rombel untuk diimpor.']],
            ], 422);
        }

        $existingNames = Rombel::forSchool($schoolId)
            ->pluck('name')
            ->map(fn ($name) => strtoupper(trim((string) $name)))
            ->flip()
            ->all();

        $seenInPayload = [];
        $rowErrors = [];
        $rowResults = [];
        $validRows = [];
        $skippedCount = 0;

        $defaultAcademicYear = $this->resolveAcademicYear($schoolId, $request->input('academic_year_id'));
        $yearCache = [];

        foreach ($items as $idx => $item) {
            $rowNumber = $item['row'] ?? ($idx + 1);
            $name = trim((string) ($item['name'] ?? ''));
            $rawAcademicYear = trim((string) ($item['academic_year'] ?? ''));
            $normName = strtoupper($name);

            if ($name === '') {
                $rowErrors[] = "Baris {$rowNumber}: Nama Kelas / Rombel wajib diisi.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'name' => '-',
                    'status' => 'error',
                    'reason' => 'Nama Kelas / Rombel kosong.',
                ];
                continue;
            }

            if (strlen($name) > 100) {
                $rowErrors[] = "Baris {$rowNumber}: Nama Kelas / Rombel '{$name}' melebihi 100 karakter.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'name' => $name,
                    'status' => 'error',
                    'reason' => 'Nama Kelas melebihi 100 karakter.',
                ];
                continue;
            }

            if (strlen($rawAcademicYear) > 20) {
                $rowErrors[] = "Baris {$rowNumber}: Tahun Ajaran '{$rawAcademicYear}' melebihi 20 karakter.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'name' => $name,
                    'status' => 'error',
                    'reason' => 'Tahun Ajaran melebihi 20 karakter.',
                ];
                continue;
            }

            if (isset($seenInPayload[$normName])) {
                $firstRow = $seenInPayload[$normName];
                $skippedCount++;
                $rowErrors[] = "Baris {$rowNumber}: Kelas '{$name}' duplikat di dalam file (baris {$firstRow}).";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'name' => $name,
                    'status' => 'dilewati',
                    'reason' => "Duplikat dengan baris {$firstRow} di file ini.",
                ];
                continue;
            }

            if (isset($existingNames[$normName])) {
                $skippedCount++;
                $rowErrors[] = "Baris {$rowNumber}: Kelas '{$name}' sudah terdaftar di sekolah Anda.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'name' => $name,
                    'status' => 'dilewati',
                    'reason' => 'Nama Kelas / Rombel sudah terdaftar di sekolah.',
                ];
                continue;
            }

            $rowAcademicYear = $this->resolveAcademicYearByName(
                $schoolId,
                $rawAcademicYear,
                $defaultAcademicYear,
                $yearCache
            );

            $seenInPayload[$normName] = $rowNumber;
            $validRows[] = [
                'school_id' => $schoolId,
                'name' => $name,
                'academic_year_id' => $rowAcademicYear?->id,
                'academic_year' => $rowAcademicYear?->name ?? ($rawAcademicYear !== '' ? $rawAcademicYear : '2026/2027'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $rowResults[] = [
                'row' => $rowNumber,
                'name' => $name,
                'status' => 'berhasil',
                'reason' => 'Berhasil disimpan.',
            ];
        }

        if (count($validRows) === 0) {
            return response()->json([
                'message' => 'Terdapat kesalahan validasi atau seluruh baris dilewati.',
                'errors' => [
                    'rows' => count($rowErrors) > 0
                        ? $rowErrors
                        : ['Tidak ada baris kelas/rombel baru yang valid untuk disimpan.'],
                ],
                'results' => $rowResults,
                'summary' => [
                    'imported' => 0,
                    'skipped' => $skippedCount,
                    'errors' => max(0, count($rowErrors) - $skippedCount),
                ],
            ], 422);
        }

        DB::transaction(function () use ($validRows) {
            Rombel::insert($validRows);
        });

        return response()->json([
            'message' => count($validRows) . ' kelas / rombel berhasil diimpor.',
            'imported_count' => count($validRows),
            'results' => $rowResults,
            'summary' => [
                'imported' => count($validRows),
                'skipped' => $skippedCount,
                'errors' => max(0, count($rowErrors) - $skippedCount),
            ],
        ], 201);
    }

    /**
     * GET /api/admin/rombels/{rombel}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $rombel = Rombel::forSchool($schoolId)
            ->withCount('students')
            ->findOrFail($id);

        return response()->json([
            'data' => $rombel,
        ]);
    }

    /**
     * PUT /api/admin/rombels/{rombel}
     * Ubah nama rombel/kelas.
     */
    public function update(RombelRequest $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $rombel = Rombel::forSchool($schoolId)->findOrFail($id);

        $academicYear = $this->resolveAcademicYear($schoolId, $request->input('academic_year_id'));

        $rombel->update([
            'name' => trim((string) $request->input('name')),
            'academic_year_id' => $academicYear?->id ?? $rombel->academic_year_id,
            'academic_year' => $academicYear?->name ?? $rombel->academic_year ?? '2026/2027',
        ]);

        $rombel->load('academicYear')->loadCount('students');

        return response()->json([
            'message' => 'Kelas / Rombel berhasil diperbarui.',
            'data' => $rombel,
        ]);
    }

    /**
     * DELETE /api/admin/rombels/{rombel}
     * Hapus rombel/kelas.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $rombel = Rombel::forSchool($schoolId)->findOrFail($id);
        $rombel->delete();

        return response()->json([
            'message' => 'Kelas / Rombel berhasil dihapus.',
        ]);
    }
}
