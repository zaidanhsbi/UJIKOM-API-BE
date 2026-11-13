<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBank extends Model
{
    use HasFactory, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'owner_id',
        'name',
        'subject_id',
        'grade',
        'visibility',
        'source_id',
    ];

    /**
     * Relasi ke sekolah pemilik bank soal.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Relasi ke guru pemilik bank soal.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Relasi ke mata pelajaran bank soal.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Relasi ke bank soal sumber jika hasil duplikasi.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'source_id');
    }

    /**
     * Relasi ke daftar soal di dalam bank ini.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'bank_id');
    }
}
