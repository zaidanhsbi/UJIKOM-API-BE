<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /**
     * Menginisialisasi controller dengan layanan pengaturan.
     */
    public function __construct(private readonly SettingService $settingService)
    {
    }

    /**
     * Menampilkan pengaturan sekolah saat ini.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'settings' => $this->settingService->getSettings($request->user()),
        ]);
    }

    /**
     * Memperbarui pengaturan profil sekolah.
     */
    public function update(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;

        $validated = $request->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'npsn' => ['required', 'string', 'max:20', Rule::unique('schools', 'npsn')->ignore($schoolId)],
            'active_academic_year' => ['required', 'string', 'max:20'],
            'active_semester' => ['required', Rule::in(['Ganjil', 'Genap'])],
        ]);

        $settings = $this->settingService->updateSettings($request->user(), $validated);

        return response()->json([
            'message' => 'Pengaturan sekolah berhasil diperbarui.',
            'settings' => $settings,
        ]);
    }

    /**
     * Mengubah kata sandi akun admin.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->settingService->changePassword(
            $request->user(),
            $validated['current_password'],
            $validated['new_password']
        );

        return response()->json([
            'message' => 'Password berhasil diperbarui.',
        ]);
    }
}
