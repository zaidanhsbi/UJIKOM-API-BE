<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoleGuru
{
    /**
     * Handle an incoming request.
     * Tolak akses (403) jika role pengguna bukan 'guru'.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Tidak terautentikasi.',
            ], 401);
        }

        if ($user->role !== 'guru') {
            return response()->json([
                'message' => 'Akses ditolak. Halaman ini hanya untuk Guru.',
                'code' => 'FORBIDDEN_ROLE',
            ], 403);
        }

        return $next($request);
    }
}
