<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherRequest;
use App\Models\Rombel;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TeacherController extends Controller
{
    /**
     * GET /api/admin/teachers
     * Daftar guru (ter-scope school_id) beserta mapel & kelas yang diajar.
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 0);

        $query = User::forSchool($schoolId)
            ->where('role', 'guru')
            ->with(['teachingAssignments.subject:id,code,name', 'teachingAssignments.rombel:id,name']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');

        if ($perPage > 0) {
            $paginator = $query->paginate(min($perPage, 100));

            return response()->json([
                'data' => collect($paginator->items())->map(fn ($t) => $this->present($t))->values(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        return response()->json([
            'data' => $query->get()->map(fn ($t) => $this->present($t))->values(),
        ]);
    }

    /**
     * POST /api/admin/teachers
     */
    public function store(TeacherRequest $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $plainPassword = trim((string) $request->input('password'));

        $teacher = DB::transaction(function () use ($request, $schoolId, $plainPassword) {
            $teacher = User::create([
                'school_id' => $schoolId,
                'rombel_id' => null,
                'role' => 'guru',
                'username' => trim((string) $request->input('username')),
                'nisn' => null,
                'name' => trim((string) $request->input('name')),
                'password' => $plainPassword,
                'password_plain' => $plainPassword,
                'is_active' => true,
            ]);

            $this->syncAssignments(
                $teacher,
                (array) $request->input('subject_ids', []),
                (array) $request->input('rombel_ids', [])
            );

            return $teacher;
        });

        return response()->json([
            'message' => 'Data guru berhasil ditambahkan.',
            'data' => $this->present($teacher->fresh()),
        ], 201);
    }

    /**
     * POST /api/admin/teachers/bulk-import
     * Kolom: username, name, password (opsional), subjects (teks dipisah koma), rombels (teks dipisah koma).
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $items = $request->input('teachers');

        if (! is_array($items) || count($items) === 0) {
            return response()->json([
                'message' => 'Data impor guru kosong atau tidak valid.',
                'errors' => ['teachers' => ['Tidak ada baris data guru untuk diimpor.']],
            ], 422);
        }

        $existingUsernames = User::forSchool($schoolId)
            ->whereNotNull('username')
            ->pluck('username')
            ->map(fn ($v) => strtoupper(trim((string) $v)))
            ->flip()
            ->all();

        // Mapel dicocokkan via kode ATAU nama (tidak peka huruf besar/kecil)
        $subjectLookup = [];
        foreach (Subject::forSchool($schoolId)->get(['id', 'code', 'name']) as $s) {
            $subjectLookup[strtoupper(trim($s->code))] = $s->id;
            $subjectLookup[strtoupper(trim($s->name))] = $s->id;
        }

        $rombelLookup = Rombel::forSchool($schoolId)
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($r) => [strtoupper(trim($r->name)) => $r->id])
            ->all();

        $seenInPayload = [];
        $rowErrors = [];
        $rowResults = [];
        $validRows = [];
        $skippedCount = 0;

        foreach ($items as $idx => $item) {
            $rowNumber = $item['row'] ?? ($idx + 1);
            $username = trim((string) ($item['username'] ?? ''));
            $normUsername = strtoupper($username);
            $name = trim((string) ($item['name'] ?? ''));
            $rawPassword = trim((string) ($item['password'] ?? ''));
            $plainPassword = $rawPassword !== '' ? $rawPassword : $username;
            $subjectNames = $this->splitList($item['subjects'] ?? '');
            $rombelNames = $this->splitList($item['rombels'] ?? '');

            if ($username === '' && $name === '') {
                continue;
            }

            $error = null;
            if ($username === '') {
                $error = 'Username / NUPTK kosong.';
            } elseif (strlen($username) > 100) {
                $error = 'Username / NUPTK melebihi 100 karakter.';
            } elseif ($name === '') {
                $error = 'Nama Guru kosong.';
            } elseif (strlen($plainPassword) < 4) {
                $error = 'Password minimal 4 karakter.';
            }

            $subjectIds = [];
            if ($error === null) {
                foreach ($subjectNames as $sn) {
                    $key = strtoupper($sn);
                    if (! isset($subjectLookup[$key])) {
                        $error = "Mapel '{$sn}' belum terdaftar di menu Mata Pelajaran.";
                        break;
                    }
                    $subjectIds[] = $subjectLookup[$key];
                }
            }

            if ($error !== null) {
                $rowErrors[] = "Baris {$rowNumber}: {$error}";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $username ?: '-',
                    'name' => $name ?: '-',
                    'status' => 'error',
                    'reason' => $error,
                ];
                continue;
            }

            if (isset($seenInPayload[$normUsername]) || isset($existingUsernames[$normUsername])) {
                $skippedCount++;
                $reason = isset($seenInPayload[$normUsername])
                    ? "Duplikat dengan baris {$seenInPayload[$normUsername]} di file ini."
                    : 'Username / NUPTK sudah terdaftar di sekolah.';
                $rowErrors[] = "Baris {$rowNumber}: {$reason}";
                $rowResults[] = [
                    'row' => $rowNumber,
                    'code' => $username,
                    'name' => $name,
                    'status' => 'dilewati',
                    'reason' => $reason,
                ];
                continue;
            }

            $seenInPayload[$normUsername] = $rowNumber;
            $validRows[] = [
                'username' => $username,
                'name' => $name,
                'password' => $plainPassword,
                'subject_ids' => array_values(array_unique($subjectIds)),
                'rombel_names' => $rombelNames,
            ];
            $rowResults[] = [
                'row' => $rowNumber,
                'code' => $username,
                'name' => $name,
                'status' => 'berhasil',
                'reason' => 'Berhasil disimpan.',
            ];
        }

        $summary = [
            'imported' => count($validRows),
            'skipped' => $skippedCount,
            'errors' => max(0, count($rowErrors) - $skippedCount),
        ];

        if (count($validRows) === 0) {
            return response()->json([
                'message' => 'Terdapat kesalahan validasi atau seluruh baris guru dilewati.',
                'errors' => [
                    'rows' => count($rowErrors) > 0
                        ? $rowErrors
                        : ['Tidak ada baris data guru baru yang valid untuk disimpan.'],
                ],
                'results' => $rowResults,
                'summary' => $summary,
            ], 422);
        }

        DB::transaction(function () use ($validRows, $schoolId, &$rombelLookup) {
            foreach ($validRows as $row) {
                $teacher = User::create([
                    'school_id' => $schoolId,
                    'role' => 'guru',
                    'username' => $row['username'],
                    'name' => $row['name'],
                    'password' => Hash::make($row['password']),
                    'password_plain' => $row['password'],
                    'is_active' => true,
                ]);

                // Kelas yang belum ada dibuat otomatis (sama seperti impor siswa)
                $rombelIds = [];
                foreach ($row['rombel_names'] as $rn) {
                    $key = strtoupper($rn);
                    if (! isset($rombelLookup[$key])) {
                        $rombelLookup[$key] = Rombel::create(['school_id' => $schoolId, 'name' => $rn])->id;
                    }
                    $rombelIds[] = $rombelLookup[$key];
                }

                $this->syncAssignments($teacher, $row['subject_ids'], $rombelIds);
            }
        });

        return response()->json([
            'message' => count($validRows) . ' guru berhasil diimpor ke sekolah Anda.',
            'imported_count' => count($validRows),
            'results' => $rowResults,
            'summary' => $summary,
        ], 201);
    }

    /**
     * GET /api/admin/teachers/{teacher}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $teacher = User::forSchool($schoolId)
            ->where('role', 'guru')
            ->findOrFail($id);

        return response()->json(['data' => $this->present($teacher)]);
    }

    /**
     * PUT /api/admin/teachers/{teacher}
     */
    public function update(TeacherRequest $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $teacher = User::forSchool($schoolId)
            ->where('role', 'guru')
            ->findOrFail($id);

        DB::transaction(function () use ($request, $teacher) {
            $payload = [
                'username' => trim((string) $request->input('username')),
                'name' => trim((string) $request->input('name')),
            ];

            $password = trim((string) $request->input('password', ''));
            if ($password !== '') {
                $payload['password'] = $password;
                $payload['password_plain'] = $password;
            }

            $teacher->update($payload);

            $this->syncAssignments(
                $teacher,
                (array) $request->input('subject_ids', []),
                (array) $request->input('rombel_ids', [])
            );
        });

        return response()->json([
            'message' => 'Data guru berhasil diperbarui.',
            'data' => $this->present($teacher->fresh()),
        ]);
    }

    /**
     * DELETE /api/admin/teachers/{teacher}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        User::forSchool($schoolId)
            ->where('role', 'guru')
            ->findOrFail($id)
            ->delete();

        return response()->json(['message' => 'Data guru berhasil dihapus.']);
    }

    /**
     * Simpan penugasan sebagai kombinasi setiap mapel × setiap kelas.
     * Penugasan lama milik guru ini diganti seluruhnya.
     */
    private function syncAssignments(User $teacher, array $subjectIds, array $rombelIds): void
    {
        TeacherAssignment::where('teacher_id', $teacher->id)->delete();

        $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));
        $rombelIds = array_values(array_unique(array_map('intval', $rombelIds)));

        $now = now();
        $rows = [];
        foreach ($subjectIds as $sid) {
            foreach ($rombelIds as $rid) {
                $rows[] = [
                    'school_id' => $teacher->school_id,
                    'teacher_id' => $teacher->id,
                    'subject_id' => $sid,
                    'rombel_id' => $rid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (count($rows) > 0) {
            TeacherAssignment::insert($rows);
        }
    }

    /**
     * Bentuk JSON guru: daftar mapel & kelas unik dari penugasan.
     */
    private function present(User $teacher): array
    {
        $teacher->loadMissing(['teachingAssignments.subject:id,code,name', 'teachingAssignments.rombel:id,name']);
        $assignments = $teacher->teachingAssignments;

        return [
            'id' => $teacher->id,
            'username' => $teacher->username,
            'name' => $teacher->name,
            'password_plain' => $teacher->password_plain,
            'subjects' => $assignments->pluck('subject')->filter()->unique('id')->sortBy('name')
                ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values(),
            'rombels' => $assignments->pluck('rombel')->filter()->unique('id')->sortBy('name')
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
        ];
    }

    /**
     * Memecah nilai string atau array menjadi daftar item yang bersih.
     */
    private function splitList(mixed $value): array
    {
        return collect(preg_split('/[,;]/', (string) $value))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '')
            ->unique(fn ($v) => strtoupper($v))
            ->values()
            ->all();
    }
}
