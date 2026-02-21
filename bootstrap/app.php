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
        // CORS on error responses – same hardcoded allow as AddCorsHeaders (no config)
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            $path = $request->path();
            if (!str_starts_with($path, 'api') && $path !== 'login' && $path !== 'user') {
                return $response;
            }
            $origin = $request->header('Origin');
            if (!$origin) {
                return $response;
            }
            $originTrimmed = rtrim($origin, '/');
            $allowed = ['http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173', 'http://127.0.0.1:3000', 'https://cpc-client-vj8bx.ondigitalocean.app', 'https://cpc-client-vj8hx.ondigitalocean.app'];
            $ok = in_array($originTrimmed, $allowed, true) || preg_match('#^https://cpc-client-[a-z0-9-]+\.ondigitalocean\.app$#', $originTrimmed);
            if ($ok) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Account-Id');
            }
            return $response;
        });
    })->create();
