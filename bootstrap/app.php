<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);
        $middleware->replace(
            \Illuminate\Http\Middleware\ValidatePostSize::class,
            \App\Http\Middleware\ValidatePostSize::class
        );
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) return null;
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        });
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) return null;
            return response()->json(['success' => false, 'message' => 'Unauthorized access'], 403);
        });
        $exceptions->render(function (\Illuminate\Validation\ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) return null;
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $exception->errors()], 422);
        });
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $exception, Request $request) {
            if (! $request->is('api/*')) return null;
            return response()->json([
                'success' => false,
                'message' => 'The uploaded file exceeds PHP server limits (upload_max_filesize / post_max_size).',
                'errors' => [
                    'file' => ['File size exceeds server upload limits. Please start PHP server with -d upload_max_filesize=64M -d post_max_size=64M.'],
                ],
            ], 422);
        });
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/*')) return null;
            return response()->json(['success' => false, 'message' => 'Resource not found'], 404);
        });
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*') || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException) return null;
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        });
    })->create();
