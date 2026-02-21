<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Add CORS first so every API response has headers (production often has cached config)
        $middleware->prepend(\App\Http\Middleware\AddCorsHeaders::class);
        // Disable CSRF validation for API routes (using Bearer tokens, not cookies)
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON for API routes
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }
            return $request->expectsJson();
        });
        // Add CORS to error responses so browser does not hide 401/403/500 behind "CORS error"
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            $path = $request->path();
            if (!str_starts_with($path, 'api') && $path !== 'login' && $path !== 'user') {
                return $response;
            }
            $origin = $request->header('Origin');
            if (!$origin) {
                return $response;
            }
            $allowed = config('cors.allowed_origins', []);
            $patterns = config('cors.allowed_origins_patterns', []);
            $ok = in_array($origin, $allowed, true);
            if (!$ok && !empty($patterns)) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $origin)) {
                        $ok = true;
                        break;
                    }
                }
            }
            if (!$ok && preg_match('#^https://cpc-client-[a-z0-9-]+\.ondigitalocean\.app$#', $origin)) {
                $ok = true;
            }
            if ($ok) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Account-Id');
            }
            return $response;
        });
    })->create();
