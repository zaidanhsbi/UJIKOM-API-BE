<?php

namespace App\Services\Admin;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingService
{
    /**
     * Mengambil pengaturan profil sekolah untuk admin.
     */
    public function getSettings(User $admin): array
    {
        $school = School::findOrFail($admin->school_id);

        return [
            'school_name' => $school->name,
            'npsn' => $school->npsn,
            'active_academic_year' => $school->active_academic_year ?? '2026/2027',
            'active_semester' => $school->active_semester ?? 'Ganjil',
        ];
    }

    /**
     * Memperbarui pengaturan profil sekolah oleh admin.
     */
    public function updateSettings(User $admin, array $data): array
    {
        $school = School::findOrFail($admin->school_id);

        $school->update([
            'name' => $data['school_name'],
            'npsn' => $data['npsn'],
            'active_academic_year' => $data['active_academic_year'],
            'active_semester' => $data['active_semester'],
        ]);

        return $this->getSettings($admin);
    }

    /**
     * Mengubah kata sandi akun admin setelah memverifikasi kata sandi lama.
     */
    public function changePassword(User $admin, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak sesuai.'],
            ]);
        }

        $admin->update([
            'password' => Hash::make($newPassword),
        ]);
    }
}
