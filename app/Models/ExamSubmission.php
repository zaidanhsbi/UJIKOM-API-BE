<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSubmission extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'school_id',
        'exam_id',
        'student_id',
        'started_at',
        'finished_at',
        'duration_seconds',
        'correct_count',
        'wrong_count',
        'blank_count',
        'score',
        'sync_status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_seconds' => 'integer',
        'correct_count' => 'integer',
        'wrong_count' => 'integer',
        'blank_count' => 'integer',
        'score' => 'float',
    ];

    /**
     * Relasi ke ujian yang dikerjakan.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * Relasi ke siswa yang mengerjakan ujian.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Relasi ke daftar jawaban pada pengumpulan ini.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class, 'submission_id');
    }
}
