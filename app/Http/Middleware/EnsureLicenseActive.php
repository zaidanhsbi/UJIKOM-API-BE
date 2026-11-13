<?php

namespace App\Http\Middleware;

use App\Models\License;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLicenseActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->school_id) {
            return response()->json([
                'message' => 'Akses tidak diizinkan.',
            ], 401);
        }

        $license = License::forSchool($user->school_id)
            ->orderByDesc('expires_at')
            ->first();

        $isLicenseValid = $license && $license->isValid();

        if (! $isLicenseValid) {
            // Siswa tidak boleh melakukan aksi yang membutuhkan lisensi aktif
            if ($user->role === 'siswa') {
                return response()->json([
                    'message' => 'Lisensi sekolah tidak aktif atau telah kedaluwarsa.',
                    'code' => 'LICENSE_EXPIRED',
                ], 403);
            }

            // Admin & Guru masuk MODE TERBATAS (hanya boleh membaca / GET)
            if (! $request->isMethodSafe()) {
                return response()->json([
                    'message' => 'Mode Terbatas: Lisensi sekolah telah habis. Anda hanya dapat melihat dan mengekspor data.',
                    'code' => 'RESTRICTED_MODE',
                ], 403);
            }
        }

        return $next($request);
    }
}

