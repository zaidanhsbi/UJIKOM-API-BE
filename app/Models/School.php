<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'npsn',
        'active_academic_year',
        'active_semester',
        'is_active',
    ];

    /**
     * Mendefinisikan konversi tipe atribut model.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi ke daftar lisensi sekolah.
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * Relasi ke lisensi terbaru berdasarkan tanggal kedaluwarsa.
     */
    public function latestLicense(): HasOne
    {
        return $this->hasOne(License::class)->latestOfMany('expires_at');
    }

    /**
     * Relasi ke daftar pengguna di sekolah ini.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Relasi ke daftar rombel di sekolah ini.
     */
    public function rombels(): HasMany
    {
        return $this->hasMany(Rombel::class);
    }
}
