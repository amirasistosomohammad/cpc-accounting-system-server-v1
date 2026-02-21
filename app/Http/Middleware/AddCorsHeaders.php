<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures CORS headers are on every API response (including 401, 403, 422, 500).
 * Browsers report "CORS error" when the response has no Access-Control-Allow-Origin,
 * so we add them here for allowed origins.
 */
class AddCorsHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isCorsPath($request)) {
            return $next($request);
        }

        // Handle preflight OPTIONS so browser gets CORS headers immediately
        if ($request->isMethod('OPTIONS')) {
            return $this->addCorsToResponse(response('', 200), $request);
        }

        $response = $next($request);
        return $this->addCorsToResponse($response, $request);
    }

    private function isCorsPath(Request $request): bool
    {
        $path = $request->path();
        // All API and auth paths
        if (str_starts_with($path, 'api')) {
            return true;
        }
        return in_array($path, ['login', 'logout', 'user', 'admin/login', 'personnel/login', 'sanctum/csrf-cookie'], true)
            || str_starts_with($path, 'storage/')
            || str_starts_with($path, 'personnel-avatar/');
    }

    private function addCorsToResponse(Response $response, Request $request): Response
    {
        $origin = $request->header('Origin');
        if (!$origin) {
            return $response;
        }

        if (!$this->isOriginAllowed($origin)) {
            return $response;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Account-Id');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');

        return $response;
    }

    private function isOriginAllowed(string $origin): bool
    {
        $allowed = config('cors.allowed_origins', []);
        if (in_array($origin, $allowed, true)) {
            return true;
        }
        $patterns = config('cors.allowed_origins_patterns', []);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }
        // Fallback: allow CPC client on DigitalOcean even if config is cached wrong
        if (preg_match('#^https://cpc-client-[a-z0-9-]+\.ondigitalocean\.app$#', $origin)) {
            return true;
        }
        return false;
    }
}
