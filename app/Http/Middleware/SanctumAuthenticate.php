<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;

class SanctumAuthenticate
{
    /** Add CORS to 401 so browser can read response and client can redirect to login */
    private function json401(Request $request, string $message): Response
    {
        $response = response()->json(['success' => false, 'message' => $message], 401);
        $origin = $request->header('Origin');
        if ($origin && ((str_contains($origin, 'cpc-client-') && str_contains($origin, 'ondigitalocean.app')) || in_array($origin, ['http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173', 'http://127.0.0.1:3000'], true))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Account-Id');
        }
        return $response;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return $this->json401($request, 'Unauthenticated');
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return $this->json401($request, 'Invalid token');
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return $this->json401($request, 'Token expired');
        }

        $user = $accessToken->tokenable;
        if ($user) {
            $request->setUserResolver(function () use ($user) {
                return $user;
            });
        }

        return $next($request);
    }
}
