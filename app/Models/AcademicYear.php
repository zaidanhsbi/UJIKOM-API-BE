<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'semester',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Membatasi query tahun ajaran berdasarkan sekolah.
     */
    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Relasi ke model sekolah.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Relasi ke daftar rombel pada tahun ajaran ini.
     */
    public function rombels(): HasMany
    {
        return $this->hasMany(Rombel::class);
    }

    /**
     * Relasi ke daftar siswa pada tahun ajaran ini.
     */
    public function students(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
