<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\License;
use App\Models\Rombel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/admin/dashboard-stats
     * Menghitung statistik sekolah ter-scope school_id milik Admin yang sedang login.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        $totalStudents = User::forSchool($schoolId)
            ->where('role', 'siswa')
            ->where('is_active', true)
            ->count();

        $totalTeachers = User::forSchool($schoolId)
            ->where('role', 'guru')
            ->count();

        $totalRombels = Rombel::forSchool($schoolId)->count();

        $activeExams = Exam::forSchool($schoolId)
            ->where('status', 'ongoing')
            ->count();

        $license = License::forSchool($schoolId)
            ->orderByDesc('expires_at')
            ->first();

        return response()->json([
            'total_students' => $totalStudents,
            'total_teachers' => $totalTeachers,
            'total_rombels' => $totalRombels,
            'active_exams' => $activeExams,
            'license' => $license ? [
                'plan' => $license->plan,
                'max_students' => $license->max_students,
                'used_students' => $totalStudents,
                'status' => $license->status,
                'expires_at' => $license->expires_at?->toIso8601String(),
                'is_valid' => $license->isValid(),
            ] : null,
        ]);
    }
}
