<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoleAdmin
{
    /**
     * Handle an incoming request.
     * Tolak akses (403) jika role pengguna bukan 'admin'.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Tidak terautentikasi.',
            ], 401);
        }

        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Halaman ini hanya untuk Admin Sekolah.',
                'code' => 'FORBIDDEN_ROLE',
            ], 403);
        }

        return $next($request);
    }
}

