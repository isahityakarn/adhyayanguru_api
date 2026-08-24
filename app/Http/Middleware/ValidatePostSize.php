<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

class ValidatePostSize
{
    /**
     * Handle an incoming request with custom 64MB limit.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Set maximum allowed POST payload size to 64MB (67,108,864 bytes)
        $max = 64 * 1024 * 1024;

        $contentLength = $request->server('CONTENT_LENGTH');

        if ($max > 0 && $contentLength && (int) $contentLength > $max) {
            throw new PostTooLargeException;
        }

        return $next($request);
    }
}
