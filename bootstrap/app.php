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
        $middleware->prepend(\App\Http\Middleware\AddCorsHeaders::class);
        $middleware->validateCsrfTokens(except: ['api/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON for API routes
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }
            return $request->expectsJson();
        });

        // Add CORS headers to API error responses so browser doesn't report "CORS error" for 401/422
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            if (!$request->is('api/*') && !$request->is('login') && !$request->is('user')) {
                return $response;
            }
            $origin = $request->header('Origin');
            if (!$origin) {
                return $response;
            }
            $allowed = config('cors.allowed_origins', []);
            $patterns = config('cors.allowed_origins_patterns', []);
            $allowedOrigin = in_array($origin, $allowed, true);
            if (!$allowedOrigin && !empty($patterns)) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $origin)) {
                        $allowedOrigin = true;
                        break;
                    }
                }
            }
            if ($allowedOrigin) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Allow-Methods', implode(', ', config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])));
                $response->headers->set('Access-Control-Allow-Headers', implode(', ', config('cors.allowed_headers', ['*'])));
            }
            return $response;
        });
    })->create();
