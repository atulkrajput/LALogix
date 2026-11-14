<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Seo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // JSON API for the React frontend: session-based (web group) under /api.
            Route::middleware('web')->prefix('api')->group(base_path('routes/api.php'));

            // Ingest API for the products: stateless, API-key auth, no session/CSRF.
            Route::middleware(['throttle:120,1', \App\Http\Middleware\ProductApiKey::class])
                ->prefix('api/ingest/v1')->group(base_path('routes/ingest.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Unauthenticated admin page visits go to the login screen;
        // logged-in users hitting /admin/login go to the dashboard.
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API: always answer JSON in the old { error } shape.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $errors = $e->errors();

                return response()->json(['error' => collect($errors)->flatten()->first() ?: 'Invalid data', 'errors' => $errors], 422);
            }

            $status = match (true) {
                $e instanceof \Illuminate\Auth\AuthenticationException => 401,
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
                $e instanceof \Illuminate\Session\TokenMismatchException => 419,
                $e instanceof HttpException => $e->getStatusCode(),
                default => 500,
            };

            $message = match ($status) {
                401 => 'Not authenticated',
                404 => 'Not found',
                419 => 'Session expired, please reload the page',
                429 => 'Too many requests',
                500 => 'Internal server error',
                default => $e->getMessage() ?: 'Error',
            };

            if ($status === 500) {
                report($e);
            }

            return response()->json(['error' => $message], $status);
        });

        // Website: render the React 404 page for unknown URLs.
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if ($response->getStatusCode() === 404 && ! $request->is('api/*') && ! $request->expectsJson()) {
                $meta = Seo::resolve(Seo::page('notFound'));

                return Inertia::render('NotFound', ['meta' => ['title' => $meta['title']]])
                    ->withViewData(['seo' => $meta])
                    ->toResponse($request)
                    ->setStatusCode(404);
            }

            return $response;
        });
    })->create();
