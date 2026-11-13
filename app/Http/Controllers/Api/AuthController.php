<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\License;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/login
     * Urutan Cek: NPSN (atau dari user email/username/nisn) -> Cek Lisensi (DB) -> Cek User -> Hash Password
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $npsn = trim((string) $request->input('npsn', ''));
        $identifier = trim($request->getLoginIdentifier());
        $password = (string) $request->input('password');

        if ($identifier === '') {
            return response()->json([
                'message' => 'Email, Username, atau NISN wajib diisi.',
            ], 422);
        }

        // Rate limit per npsn + identifier (bukan per IP sesuai aturan arsitektur)
        $throttleKey = 'login:' . Str::lower(($npsn ?: 'any') . '|' . $identifier);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Terlalu banyak percobaan masuk. Silakan coba lagi dalam {$seconds} detik.",
                'code' => 'TOO_MANY_ATTEMPTS',
            ], 429);
        }

        // 1. Cek NPSN Sekolah (jika dikirim dari input, atau temukan otomatis dari user jika NPSN dikosongkan)
        $school = null;
        if ($npsn !== '') {
            $school = School::where('npsn', $npsn)->first();
        } else {
            // Jika input hanya mengirim email/username tanpa NPSN, cari sekolah dari user terkait
            $matchedUser = User::where('email', $identifier)
                ->orWhere('username', $identifier)
                ->orWhere('nisn', $identifier)
                ->first();
            if ($matchedUser) {
                $school = School::find($matchedUser->school_id);
            }
        }

        if (! $school || ! $school->is_active) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'message' => 'NPSN sekolah tidak ditemukan atau akun sekolah sedang dinonaktifkan.',
                'code' => 'SCHOOL_NOT_FOUND',
            ], 404);
        }

        // 2. Cek Lisensi (DB) SEBELUM bcrypt
        $license = License::forSchool($school->id)
            ->orderByDesc('expires_at')
            ->first();

        if (! $license) {
            return response()->json([
                'message' => 'Sekolah ini belum memiliki lisensi terdaftar di database.',
                'code' => 'LICENSE_MISSING',
            ], 403);
        }

        $isLicenseValid = $license->isValid();

        if ($license->status === 'suspended') {
            return response()->json([
                'message' => 'Lisensi sekolah sedang ditangguhkan (suspended). Hubungi pengelola.',
                'code' => 'LICENSE_SUSPENDED',
            ], 403);
        }

        // 3. Cek User (email, username, atau nisn) ter-scope school_id
        $user = User::forSchool($school->id)
            ->where(function ($query) use ($identifier) {
                $query->where('email', $identifier)
                    ->orWhere('username', $identifier)
                    ->orWhere('nisn', $identifier);
            })
            ->first();

        if (! $user) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'message' => 'Email/Username/NISN atau kata sandi salah.',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun Anda sedang dinonaktifkan. Hubungi Admin Sekolah.',
                'code' => 'USER_INACTIVE',
            ], 403);
        }

        // Jika lisensi kedaluwarsa dan role adalah siswa, tolak sebelum bcrypt
        if (! $isLicenseValid && $user->role === 'siswa') {
            return response()->json([
                'message' => 'Masa aktif lisensi sekolah telah berakhir. Ujian baru tidak dapat dimulai.',
                'code' => 'LICENSE_EXPIRED',
            ], 403);
        }

        // 4. Cek Hash Password
        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'message' => 'Email/Username/NISN atau kata sandi salah.',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        RateLimiter::clear($throttleKey);

        // Buat token Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        $restrictedMode = ! $isLicenseValid && in_array($user->role, ['admin', 'guru'], true);

        return response()->json([
            'message' => $restrictedMode
                ? 'Login berhasil (Mode Terbatas: Lisensi sekolah telah habis).'
                : 'Login berhasil.',
            'token' => $token,
            'restricted_mode' => $restrictedMode,
            'user' => [
                'id' => $user->id,
                'school_id' => $user->school_id,
                'role' => $user->role,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'nisn' => $user->nisn,
                'is_active' => $user->is_active,
                'school' => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'npsn' => $school->npsn,
                ],
                'license' => [
                    'plan' => $license->plan,
                    'status' => $license->status,
                    'max_students' => $license->max_students,
                    'starts_at' => $license->starts_at?->toIso8601String(),
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'is_valid' => $isLicenseValid,
                ],
            ],
        ]);
    }

    /**
     * POST /api/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Berhasil keluar.',
        ]);
    }
}
