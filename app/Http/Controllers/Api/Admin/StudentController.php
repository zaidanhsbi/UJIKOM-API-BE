<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\AcademicYear;
use App\Models\License;
use App\Models\Rombel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller
{
    /**
     * GET /api/admin/students
     * Ambil daftar siswa ter-scope school_id, mendukung filter rombel_id, pencarian, dan pagination server.
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $search = trim((string) $request->query('search', ''));
        $rombelId = $request->query('rombel_id');
        $perPage = (int) $request->query('per_page', 0);

        $query = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->with(['rombel:id,school_id,name,academic_year,academic_year_id', 'academicYear:id,name,semester']);

        if ($rombelId !== null && $rombelId !== '') {
            $query->where('rombel_id', (int) $rombelId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nisn', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
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
     * POST /api/admin/students
     * Tambah akun siswa baru dan masukkan ke rombel.
     */
    public function store(StudentRequest $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $license = License::forSchool($schoolId)->orderByDesc('expires_at')->first();
        if ($license) {
            $currentCount = User::forSchool($schoolId)
                ->where('role', 'siswa')
                ->where('is_active', true)
                ->count();
            if ($currentCount >= $license->max_students) {
                return response()->json([
                    'message' => "Kuota siswa lisensi sekolah telah penuh ({$license->max_students} siswa).",
                ], 422);
            }
        }

        $nisn = trim((string) $request->input('nisn'));
        $plainPassword = trim((string) $request->input('password'));
        $academicYearId = $request->input('academic_year_id');
        $academicYear = $academicYearId
            ? AcademicYear::forSchool($schoolId)->find((int) $academicYearId)
            : AcademicYear::forSchool($schoolId)->where('is_active', true)->first();

        $student = User::create([
            'school_id' => $schoolId,
            'rombel_id' => (int) $request->input('rombel_id'),
            'academic_year_id' => $academicYear?->id,
            'role' => 'siswa',
            'nisn' => $nisn,
            'username' => $nisn,
            'name' => trim((string) $request->input('name')),
            'password' => $plainPassword,
            'password_plain' => $plainPassword,
            'is_active' => true,
        ]);

        $student->load(['rombel:id,school_id,name,academic_year,academic_year_id', 'academicYear:id,name,semester']);

        return response()->json([
            'message' => 'Data siswa berhasil ditambahkan.',
            'data' => $student,
        ], 201);
    }

    /**
     * POST /api/admin/students/bulk-import
     * Impor massal siswa (NISN, Nama, Rombel/Kelas, Password opsional default = NISN).
     * Jika nama Rombel belum ada di sekolah, otomatis dibuatkan agar ramah admin gaptek.
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $items = $request->input('students');

        if (! is_array($items) || count($items) === 0) {
            return response()->json([
                'message' => 'Data impor siswa kosong atau tidak valid.',
                'errors' => ['students' => ['Tidak ada baris data siswa untuk diimpor.']],
            ], 422);
        }

        $license = License::forSchool($schoolId)->orderByDesc('expires_at')->first();
        $currentCount = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->where('is_active', true)
            ->count();
        $maxStudents = $license ? (int) $license->max_students : 999999;

        $existingNisns = User::forSchool($schoolId)
            ->whereNotNull('nisn')
            ->pluck('nisn')
            ->map(fn ($v) => strtoupper(trim((string) $v)))
            ->flip()
            ->all();

        $existingUsernames = User::forSchool($schoolId)
            ->whereNotNull('username')
            ->pluck('username')
            ->map(fn ($v) => strtoupper(trim((string) $v)))
            ->flip()
            ->all();

        $rombelsByNormName = [];
        foreach (Rombel::forSchool($schoolId)->get() as $r) {
            $rombelsByNormName[strtoupper(trim((string) $r->name))] = $r->id;
        }

        $seenNisnInPayload = [];
        $rowErrors = [];
        $rowResults = [];
        $validRows = [];
        $skippedCount = 0;

        foreach ($items as $idx => $item) {
            $rowNumber = $item['row'] ?? ($idx + 1);
            $nisn = trim((string) ($item['nisn'] ?? ''));
            $normNisn = strtoupper($nisn);
            $name = trim((string) ($item['name'] ?? ''));
            $rombelName = trim((string) ($item['rombel'] ?? ''));
            $normRombel = strtoupper($rombelName);
            $rawPassword = trim((string) ($item['password'] ?? ''));
            $plainPassword = $rawPassword !== '' ? $rawPassword : $nisn;

            if ($nisn === '' && $name === '') {
                continue;
            }

            if ($nisn === '') {
                $rowErrors[] = "Baris {$rowNumber}: NISN wajib diisi.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => '-',
                    'name' => $name ?: '-',
                    'extra' => $rombelName ?: '-',
                    'status' => 'error',
                    'reason' => 'NISN kosong.',
                ];
                continue;
            }

            if ($name === '') {
                $rowErrors[] = "Baris {$rowNumber} (NISN {$nisn}): Nama Siswa wajib diisi.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $nisn,
                    'name' => '-',
                    'extra' => $rombelName ?: '-',
                    'status' => 'error',
                    'reason' => 'Nama Siswa kosong.',
                ];
                continue;
            }

            if ($rombelName === '') {
                $rowErrors[] = "Baris {$rowNumber} (NISN {$nisn}): Kelas / Rombel wajib diisi.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $nisn,
                    'name' => $name,
                    'extra' => '-',
                    'status' => 'error',
                    'reason' => 'Kelas / Rombel kosong.',
                ];
                continue;
            }

            if (strlen($plainPassword) < 4) {
                $rowErrors[] = "Baris {$rowNumber} (NISN {$nisn}): Password minimal 4 karakter.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $nisn,
                    'name' => $name,
                    'extra' => $rombelName,
                    'status' => 'error',
                    'reason' => 'Password minimal 4 karakter.',
                ];
                continue;
            }

            if (isset($seenNisnInPayload[$normNisn])) {
                $firstRow = $seenNisnInPayload[$normNisn];
                $skippedCount++;
                $rowErrors[] = "Baris {$rowNumber}: NISN '{$nisn}' duplikat di dalam file (baris {$firstRow}).";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $nisn,
                    'name' => $name,
                    'extra' => $rombelName,
                    'status' => 'dilewati',
                    'reason' => "NISN duplikat dengan baris {$firstRow} di file ini.",
                ];
                continue;
            }

            if (isset($existingNisns[$normNisn]) || isset($existingUsernames[$normNisn])) {
                $skippedCount++;
                $rowErrors[] = "Baris {$rowNumber}: NISN '{$nisn}' sudah terdaftar di sekolah Anda.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $nisn,
                    'name' => $name,
                    'extra' => $rombelName,
                    'status' => 'dilewati',
                    'reason' => 'NISN sudah terdaftar di sekolah.',
                ];
                continue;
            }

            if (($currentCount + count($validRows)) >= $maxStudents) {
                $rowErrors[] = "Baris {$rowNumber}: Kuota lisensi siswa penuh ({$maxStudents} siswa).";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $nisn,
                    'name' => $name,
                    'extra' => $rombelName,
                    'status' => 'error',
                    'reason' => "Melebihi kuota lisensi ({$maxStudents} siswa).",
                ];
                continue;
            }

            $seenNisnInPayload[$normNisn] = $rowNumber;
            $validRows[] = [
                'row' => $rowNumber,
                'nisn' => $nisn,
                'name' => $name,
                'rombel_name' => $rombelName,
                'norm_rombel' => $normRombel,
                'academic_year' => trim((string) ($item['academic_year'] ?? '')),
                'password_plain' => $plainPassword,
            ];
            $rowResults[] = [
                'row' => $rowNumber,
                'code' => $nisn,
                'name' => $name,
                'extra' => $rombelName,
                'status' => 'berhasil',
                'reason' => 'Berhasil disimpan.',
            ];
        }

        if (count($validRows) === 0) {
            return response()->json([
                'message' => 'Terdapat kesalahan validasi atau seluruh baris siswa dilewati.',
                'errors' => [
                    'rows' => count($rowErrors) > 0
                        ? $rowErrors
                        : ['Tidak ada baris data siswa baru yang valid untuk disimpan.'],
                ],
                'results' => $rowResults,
                'summary' => [
                    'imported' => 0,
                    'skipped' => $skippedCount,
                    'errors' => max(0, count($rowErrors) - $skippedCount),
                ],
            ], 422);
        }

        $academicYearId = $request->input('academic_year_id');
        $defaultAcademicYear = $academicYearId
            ? AcademicYear::forSchool($schoolId)->find((int) $academicYearId)
            : AcademicYear::forSchool($schoolId)->where('is_active', true)->first();

        DB::transaction(function () use ($schoolId, $validRows, &$rombelsByNormName, $defaultAcademicYear) {
            $insertPayload = [];
            $now = now();
            $yearCache = [];

            foreach ($validRows as $v) {
                $rawAy = trim((string) ($v['academic_year'] ?? ''));
                $rowAcademicYear = $defaultAcademicYear;

                if ($rawAy !== '') {
                    $ayKey = strtoupper($rawAy);
                    if (! array_key_exists($ayKey, $yearCache)) {
                        $foundAy = AcademicYear::forSchool($schoolId)
                            ->where('name', $rawAy)
                            ->orderByDesc('is_active')
                            ->first();
                        if (! $foundAy) {
                            $foundAy = AcademicYear::create([
                                'school_id' => $schoolId,
                                'name' => $rawAy,
                                'semester' => 'Ganjil',
                                'is_active' => false,
                            ]);
                        }
                        $yearCache[$ayKey] = $foundAy;
                    }
                    $rowAcademicYear = $yearCache[$ayKey];
                }

                $normRombel = $v['norm_rombel'];
                if (! isset($rombelsByNormName[$normRombel])) {
                    $createdRombel = Rombel::create([
                        'school_id' => $schoolId,
                        'name' => $v['rombel_name'],
                        'academic_year_id' => $rowAcademicYear?->id,
                        'academic_year' => $rowAcademicYear?->name ?? ($rawAy !== '' ? $rawAy : '2026/2027'),
                    ]);
                    $rombelsByNormName[$normRombel] = $createdRombel->id;
                }

                $insertPayload[] = [
                    'school_id' => $schoolId,
                    'rombel_id' => $rombelsByNormName[$normRombel],
                    'academic_year_id' => $rowAcademicYear?->id,
                    'role' => 'siswa',
                    'nisn' => $v['nisn'],
                    'username' => $v['nisn'],
                    'name' => $v['name'],
                    'password' => Hash::make($v['password_plain']),
                    'password_plain' => $v['password_plain'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            User::insert($insertPayload);
        });

        return response()->json([
            'message' => count($validRows) . ' siswa berhasil diimpor ke sekolah Anda.',
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
     * GET /api/admin/students/{student}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $student = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->with('rombel:id,school_id,name')
            ->findOrFail($id);

        return response()->json([
            'data' => $student,
        ]);
    }

    /**
     * PUT /api/admin/students/{student}
     * Perbarui data siswa (NISN, Nama, Rombel, dan Password opsional).
     */
    public function update(StudentRequest $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $student = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->findOrFail($id);

        $nisn = trim((string) $request->input('nisn'));

        $academicYearId = $request->input('academic_year_id');
        $payload = [
            'rombel_id' => (int) $request->input('rombel_id'),
            'academic_year_id' => $academicYearId ? (int) $academicYearId : $student->academic_year_id,
            'nisn' => $nisn,
            'username' => $nisn,
            'name' => trim((string) $request->input('name')),
        ];

        $password = trim((string) $request->input('password', ''));
        if ($password !== '') {
            $payload['password'] = $password;
            $payload['password_plain'] = $password;
        }

        $student->update($payload);
        $student->load(['rombel:id,school_id,name,academic_year,academic_year_id', 'academicYear:id,name,semester']);

        return response()->json([
            'message' => 'Data siswa berhasil diperbarui.',
            'data' => $student,
        ]);
    }

    /**
     * DELETE /api/admin/students/{student}
     * Hapus akun siswa.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $student = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->findOrFail($id);

        $student->delete();

        return response()->json([
            'message' => 'Data siswa berhasil dihapus.',
        ]);
    }

    /**
     * POST /api/admin/students/promote
     * Naik kelas massal atau kelulusan (nonaktifkan siswa kelas XII).
     */
    public function promote(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $validated = $request->validate([
            'source_rombel_id' => ['required', 'integer'],
            'action' => ['required', 'in:promote,graduate'],
            'target_rombel_id' => ['nullable', 'integer', 'required_if:action,promote'],
        ]);

        $sourceRombel = Rombel::forSchool($schoolId)->findOrFail((int) $validated['source_rombel_id']);

        $query = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->where('rombel_id', $sourceRombel->id)
            ->where('is_active', true);

        if ($validated['action'] === 'graduate') {
            $affected = $query->update(['is_active' => false]);

            return response()->json([
                'message' => "{$affected} siswa dari {$sourceRombel->name} berhasil diluluskan (status Nonaktif).",
                'affected_count' => $affected,
            ]);
        }

        $targetRombel = Rombel::forSchool($schoolId)->findOrFail((int) $validated['target_rombel_id']);
        if ($targetRombel->id === $sourceRombel->id) {
            return response()->json([
                'message' => 'Kelas tujuan tidak boleh sama dengan kelas asal.',
            ], 422);
        }

        $affected = $query->update([
            'rombel_id' => $targetRombel->id,
            'academic_year_id' => $targetRombel->academic_year_id,
        ]);

        return response()->json([
            'message' => "{$affected} siswa berhasil dinaikkan dari {$sourceRombel->name} ke {$targetRombel->name}.",
            'affected_count' => $affected,
        ]);
    }

    /**
     * PATCH /api/admin/students/{id}/toggle-active
     * Ubah status aktif/nonaktif siswa satuan.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $student = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->findOrFail($id);

        $student->update([
            'is_active' => ! $student->is_active,
        ]);

        return response()->json([
            'message' => $student->is_active
                ? "Akun siswa {$student->name} diaktifkan."
                : "Akun siswa {$student->name} dinonaktifkan.",
            'data' => $student,
        ]);
    }
}
