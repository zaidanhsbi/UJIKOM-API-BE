<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class License extends Model
{
    use HasFactory, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'plan',
        'max_students',
        'starts_at',
        'expires_at',
        'status',
    ];

    /**
     * Mendefinisikan konversi tipe atribut model.
     */
    protected function casts(): array
    {
        return [
            'max_students' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Relasi ke sekolah pemilik lisensi.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Memeriksa apakah lisensi masih aktif dan berlaku.
     */
    public function isValid(): bool
    {
        return $this->status === 'active'
            && $this->starts_at <= now()
            && $this->expires_at > now();
    }
}

