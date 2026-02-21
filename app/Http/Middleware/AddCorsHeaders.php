<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for CPC – no config, no env. Hardcoded so production always works.
 */
class AddCorsHeaders
{
    /** @var string[] */
    private static $origins = [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:3000',
        'https://cpc-client-vj8bx.ondigitalocean.app',
        'https://cpc-client-vj8hx.ondigitalocean.app',
    ];

    /** Pattern: any cpc-client-*.ondigitalocean.app (with or without trailing slash in Origin we normalize) */
    private static function isOriginAllowed(string $origin): bool
    {
        $origin = rtrim($origin, '/');
        if (in_array($origin, self::$origins, true)) {
            return true;
        }
        return (bool) preg_match('#^https://cpc-client-[a-z0-9-]+\.ondigitalocean\.app$#', $origin);
    }

    private static function isApiOrAuthPath(Request $request): bool
    {
        $path = $request->path();
        return str_starts_with($path, 'api')
            || in_array($path, ['login', 'logout', 'user', 'admin/login', 'personnel/login', 'sanctum/csrf-cookie'], true)
            || str_starts_with($path, 'storage/')
            || str_starts_with($path, 'personnel-avatar/');
    }

    private static function addHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Account-Id');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::isApiOrAuthPath($request)) {
            return $next($request);
        }

        $origin = $request->header('Origin');
        if (!$origin || !self::isOriginAllowed($origin)) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 200);
            self::addHeaders($response, $origin);
            return $response;
        }

        $response = $next($request);
        self::addHeaders($response, $origin);
        return $response;
    }
}
