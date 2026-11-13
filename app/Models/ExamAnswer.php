<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAnswer extends Model
{
    protected $fillable = [
        'submission_id',
        'question_id',
        'answer',
        'is_correct',
        'score_earned',
        'ver',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'score_earned' => 'float',
        'ver' => 'integer',
    ];

    /**
     * Relasi ke data pengumpulan ujian siswa.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(ExamSubmission::class, 'submission_id');
    }

    /**
     * Relasi ke soal ujian yang dijawab.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
