<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan mengajar: satu baris = guru X mengajar mapel Y di kelas Z.
 */
class TeacherAssignment extends Model
{
    protected $fillable = [
        'school_id',
        'teacher_id',
        'subject_id',
        'rombel_id',
    ];

    /**
     * Relasi ke guru yang ditugaskan.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Relasi ke mata pelajaran yang diampu.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Relasi ke rombel yang diajar.
     */
    public function rombel(): BelongsTo
    {
        return $this->belongsTo(Rombel::class);
    }
}
