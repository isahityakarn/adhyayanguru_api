<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['super_admin', '1', 1], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Super-administrator access is required.',
            ], 403);
        }

        return $next($request);
    }
}
