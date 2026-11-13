<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subject extends Model
{
    use HasFactory, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'code',
        'name',
    ];

    /**
     * Relasi ke sekolah pemilik mata pelajaran.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}

