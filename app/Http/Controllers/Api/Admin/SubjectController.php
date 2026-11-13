<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectRequest;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
    /**
     * GET /api/admin/subjects
     * Ambil daftar mata pelajaran ter-scope school_id.
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 0);

        $query = Subject::forSchool($schoolId);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $query->orderBy('code');

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
     * POST /api/admin/subjects
     * Tambah mata pelajaran baru.
     */
    public function store(SubjectRequest $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $subject = Subject::create([
            'school_id' => $schoolId,
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);

        return response()->json([
            'message' => 'Mata pelajaran berhasil ditambahkan.',
            'data' => $subject,
        ], 201);
    }

    /**
     * POST /api/admin/subjects/bulk-import
     * Impor massal mata pelajaran dengan rincian per baris (berhasil, dilewati, error).
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $items = $request->input('subjects');

        if (! is_array($items) || count($items) === 0) {
            return response()->json([
                'message' => 'Data impor mata pelajaran kosong atau tidak valid.',
                'errors' => ['subjects' => ['Tidak ada baris data mata pelajaran untuk diimpor.']],
            ], 422);
        }

        $existingCodes = Subject::forSchool($schoolId)
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->flip()
            ->all();

        $seenInPayload = [];
        $rowErrors = [];
        $rowResults = [];
        $validRows = [];
        $skippedCount = 0;

        foreach ($items as $idx => $item) {
            $rowNumber = $item['row'] ?? ($idx + 1);
            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));

            if ($code === '' && $name === '') {
                continue;
            }

            if ($code === '') {
                $msg = "Baris {$rowNumber}: Kode Mapel wajib diisi.";
                $rowErrors[] = $msg;
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => '-',
                    'name' => $name ?: '-',
                    'status' => 'error',
                    'reason' => 'Kode Mapel kosong.',
                ];
                continue;
            }

            if (strlen($code) > 50) {
                $msg = "Baris {$rowNumber}: Kode Mapel '{$code}' melebihi 50 karakter.";
                $rowErrors[] = $msg;
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'name' => $name ?: '-',
                    'status' => 'error',
                    'reason' => 'Kode Mapel melebihi 50 karakter.',
                ];
                continue;
            }

            if ($name === '') {
                $msg = "Baris {$rowNumber} (Kode {$code}): Nama Mapel wajib diisi.";
                $rowErrors[] = $msg;
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'name' => '-',
                    'status' => 'error',
                    'reason' => 'Nama Mapel kosong.',
                ];
                continue;
            }

            if (strlen($name) > 150) {
                $msg = "Baris {$rowNumber} (Kode {$code}): Nama Mapel melebihi 150 karakter.";
                $rowErrors[] = $msg;
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'name' => $name,
                    'status' => 'error',
                    'reason' => 'Nama Mapel melebihi 150 karakter.',
                ];
                continue;
            }

            if (isset($seenInPayload[$code])) {
                $firstRow = $seenInPayload[$code];
                $skippedCount++;
                $rowErrors[] = "Baris {$rowNumber}: Kode Mapel '{$code}' duplikat di dalam file (sudah ada pada baris {$firstRow}).";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'name' => $name,
                    'status' => 'dilewati',
                    'reason' => "Duplikat dengan baris {$firstRow} di file ini.",
                ];
                continue;
            }

            if (isset($existingCodes[$code])) {
                $skippedCount++;
                $rowErrors[] = "Baris {$rowNumber}: Kode Mapel '{$code}' sudah terdaftar di database sekolah Anda.";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'name' => $name,
                    'status' => 'dilewati',
                    'reason' => 'Kode Mapel sudah terdaftar di sekolah.',
                ];
                continue;
            }

            $seenInPayload[$code] = $rowNumber;
            $validRows[] = [
                'school_id' => $schoolId,
                'code' => $code,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $rowResults[] = [
                'row' => $rowNumber,
                'code' => $code,
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
                        : ['Tidak ada baris mata pelajaran baru yang valid untuk disimpan.'],
                ],
                'results' => $rowResults,
                'summary' => [
                    'imported' => 0,
                    'skipped' => $skippedCount,
                    'errors' => count($rowErrors) - $skippedCount,
                ],
            ], 422);
        }

        DB::transaction(function () use ($validRows) {
            Subject::insert($validRows);
        });

        return response()->json([
            'message' => count($validRows) . ' mata pelajaran berhasil diimpor ke sekolah Anda.',
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
     * GET /api/admin/subjects/{subject}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $subject = Subject::forSchool($schoolId)->findOrFail($id);

        return response()->json([
            'data' => $subject,
        ]);
    }

    /**
     * PUT /api/admin/subjects/{subject}
     * Ubah data mata pelajaran.
     */
    public function update(SubjectRequest $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $subject = Subject::forSchool($schoolId)->findOrFail($id);

        $subject->update([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);

        return response()->json([
            'message' => 'Mata pelajaran berhasil diperbarui.',
            'data' => $subject,
        ]);
    }

    /**
     * DELETE /api/admin/subjects/{subject}
     * Hapus mata pelajaran.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $subject = Subject::forSchool($schoolId)->findOrFail($id);
        $subject->delete();

        return response()->json([
            'message' => 'Mata pelajaran berhasil dihapus.',
        ]);
    }
}
