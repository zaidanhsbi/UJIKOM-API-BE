<?php

use App\Http\Controllers\Api\Admin\AcademicYearController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\RombelController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\StudentController;
use App\Http\Controllers\Api\Admin\SubjectController;
use App\Http\Controllers\Api\Admin\TeacherController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Guru\DashboardController as GuruDashboardController;
use App\Http\Controllers\Api\Guru\ExamController;
use App\Http\Controllers\Api\Guru\QuestionBankController;
use App\Http\Controllers\Api\Guru\QuestionController;
use App\Http\Controllers\Api\Guru\RecapController;
use App\Http\Controllers\Api\Siswa\ExamController as SiswaExamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'BinoExam API is running',
    ]);
});

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        $user = $request->user()->load('school');
        return response()->json([
            'user' => [
                'id' => $user->id,
                'school_id' => $user->school_id,
                'rombel_id' => $user->rombel_id,
                'role' => $user->role,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'nisn' => $user->nisn,
                'is_active' => $user->is_active,
                'school' => $user->school ? [
                    'id' => $user->school->id,
                    'name' => $user->school->name,
                    'npsn' => $user->school->npsn,
                ] : null,
            ],
        ]);
    });

    // Rute Khusus Admin Sekolah
    Route::prefix('admin')
        ->middleware(['role.admin', 'license.active'])
        ->group(function () {
            Route::get('/dashboard-stats', [AdminDashboardController::class, 'stats']);
            Route::post('/subjects/bulk-import', [SubjectController::class, 'bulkImport']);
            Route::apiResource('/subjects', SubjectController::class);
            Route::post('/academic-years/{id}/set-active', [AcademicYearController::class, 'setActive']);
            Route::apiResource('/academic-years', AcademicYearController::class)->except(['show']);
            Route::post('/rombels/bulk-import', [RombelController::class, 'bulkImport']);
            Route::apiResource('/rombels', RombelController::class);
            Route::post('/students/promote', [StudentController::class, 'promote']);
            Route::patch('/students/{id}/toggle-active', [StudentController::class, 'toggleActive']);
            Route::post('/students/bulk-import', [StudentController::class, 'bulkImport']);
            Route::apiResource('/students', StudentController::class);
            Route::post('/teachers/bulk-import', [TeacherController::class, 'bulkImport']);
            Route::apiResource('/teachers', TeacherController::class);
            Route::get('/settings', [AdminSettingController::class, 'show']);
            Route::put('/settings', [AdminSettingController::class, 'update']);
            Route::put('/change-password', [AdminSettingController::class, 'changePassword']);
        });

    // Rute Khusus Guru
    Route::prefix('guru')
        ->middleware(['role.guru', 'license.active'])
        ->group(function () {
            Route::get('/dashboard-stats', [GuruDashboardController::class, 'stats']);
            Route::get('/question-banks/template-docx', [QuestionController::class, 'downloadDocxTemplate']);
            Route::post('/question-banks/{questionBank}/duplicate', [QuestionBankController::class, 'duplicate']);
            Route::post('/question-banks/{questionBank}/import', [QuestionController::class, 'bulkImport']);
            Route::post('/question-banks/{questionBank}/import-docx', [QuestionController::class, 'importDocx']);
            Route::apiResource('/question-banks', QuestionBankController::class);
            Route::post('/questions/upload-media', [QuestionController::class, 'uploadMedia']);
            Route::apiResource('/questions', QuestionController::class)->only(['store', 'show', 'update', 'destroy']);
            Route::post('/exams/{exam}/start', [ExamController::class, 'start']);
            Route::post('/exams/{exam}/finish', [ExamController::class, 'finish']);
            Route::apiResource('/exams', ExamController::class);
            Route::get('/recaps', [RecapController::class, 'index']);
            Route::get('/recaps/{exam}', [RecapController::class, 'show']);
            Route::delete('/recaps/{exam}', [RecapController::class, 'destroy']);
        });

    // Rute Khusus Siswa (Mobile CBT)
    Route::prefix('siswa')->group(function () {
        Route::get('/exams', [SiswaExamController::class, 'index']);
        Route::post('/exams/{exam}/start', [SiswaExamController::class, 'start']);
        Route::post('/exams/{exam}/submit', [SiswaExamController::class, 'submit']);
    });
});
