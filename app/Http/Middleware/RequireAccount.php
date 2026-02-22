<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAccount
{
    /** Add CORS to 422 so browser can read response (same as SanctumAuthenticate 401). */
    private function json422(Request $request, string $message): Response
    {
        $response = response()->json(['success' => false, 'message' => $message], 422);
        $origin = $request->header('Origin');
        if ($origin && ((str_contains($origin, 'cpc-client-') && str_contains($origin, 'ondigitalocean.app')) || in_array(rtrim($origin, '/'), ['http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173', 'http://127.0.0.1:3000'], true))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Account-Id');
        }
        return $response;
    }

    /**
     * Require that current_account_id is set (user sent valid X-Account-Id).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->attributes->get('current_account_id');
        if ($accountId === null || $accountId === '') {
            return $this->json422($request, 'Account context required. Please select an account from the topbar.');
        }

        return $next($request);
    }
}
