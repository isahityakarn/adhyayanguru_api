<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Define allowed origins
        $allowedOrigins = [
            'https://adhyayanguru.shop',
            'http://localhost:5173',
        ];

        $origin = $request->headers->get('Origin');

        // Check if the origin is allowed
        $allowedOrigin = in_array($origin, $allowedOrigins) ? $origin : 'https://adhyayanguru.shop';

        // Handle preflight requests
        if ($request->isMethod('OPTIONS')) {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowedOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token, Accept, Origin')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // Add CORS headers to the response
        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token, Accept, Origin');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Expose-Headers', 'Authorization');

        return $response;
    }
}
