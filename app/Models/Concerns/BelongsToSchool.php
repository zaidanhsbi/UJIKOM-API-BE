<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToSchool
{
    /**
     * Membatasi query model berdasarkan ID sekolah.
     */
    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where($this->qualifyColumn('school_id'), $schoolId);
    }
}

