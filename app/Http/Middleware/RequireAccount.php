<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAccount
{
    /**
     * Require that current_account_id is set (user sent valid X-Account-Id).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->attributes->get('current_account_id');
        if ($accountId === null || $accountId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Account context required. Please select an account from the topbar.',
            ], 422);
        }

        return $next($request);
    }
}
