<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'bank_id',
        'type',
        'difficulty',
        'content',
        'weight',
        'explanation',
        'version',
    ];

    protected $casts = [
        'content' => 'array',
        'weight' => 'integer',
        'version' => 'integer',
    ];

    /**
     * Relasi ke sekolah pemilik soal.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Relasi ke bank soal induk.
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'bank_id');
    }

    /**
     * Relasi ke daftar media pada soal ini.
     */
    public function media(): HasMany
    {
        return $this->hasMany(QuestionMedia::class);
    }
}
