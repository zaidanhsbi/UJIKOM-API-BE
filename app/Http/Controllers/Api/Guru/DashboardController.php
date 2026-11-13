<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/guru/dashboard-stats
     * Menghitung ringkasan beranda guru ter-scope guru dan school_id milik pengguna yang sedang login.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;

        $assignments = TeacherAssignment::query()
            ->where('school_id', $schoolId)
            ->where('teacher_id', $user->id)
            ->with(['subject:id,name,code', 'rombel:id,name'])
            ->get();

        $subjects = $assignments
            ->pluck('subject')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
            ]);

        $rombels = $assignments
            ->pluck('rombel')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($rombel) => [
                'id' => $rombel->id,
                'name' => $rombel->name,
            ]);

        $myPackagesCount = QuestionBank::forSchool($schoolId)
            ->where('owner_id', $user->id)
            ->count();

        $myQuestionsCount = Question::forSchool($schoolId)
            ->whereHas('bank', fn ($q) => $q->where('owner_id', $user->id))
            ->count();

        return response()->json([
            'teacher' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'subjects' => $subjects,
                'rombels' => $rombels,
            ],
            'stats' => [
                'exams_today' => 0,
                'my_packages' => $myPackagesCount,
                'my_questions' => $myQuestionsCount,
            ],
            'upcoming_exams' => [],
            'attention_items' => [],
        ]);
    }
}
