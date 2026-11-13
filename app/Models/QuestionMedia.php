<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionMedia extends Model
{
    use HasFactory, BelongsToSchool;

    protected $table = 'question_media';

    protected $fillable = [
        'school_id',
        'question_id',
        'file',
        'alt_text',
    ];

    /**
     * Relasi ke sekolah pemilik media.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Relasi ke soal yang memiliki media ini.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
