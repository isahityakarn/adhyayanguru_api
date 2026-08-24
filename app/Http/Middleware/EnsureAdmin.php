<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'super_admin', '1', 1, '2', 2], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Administrator access is required.',
            ], 403);
        }

        return $next($request);
    }
}