<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'rombel_id',
        'academic_year_id',
        'role',
        'name',
        'email',
        'username',
        'nisn',
        'password',
        'password_plain',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Mendefinisikan konversi tipe atribut pengguna.
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi ke sekolah pengguna.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Relasi ke rombel siswa.
     */
    public function rombel(): BelongsTo
    {
        return $this->belongsTo(Rombel::class);
    }

    /**
     * Relasi ke tahun ajaran siswa.
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Relasi ke daftar penugasan mengajar guru.
     */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'teacher_id');
    }
}
