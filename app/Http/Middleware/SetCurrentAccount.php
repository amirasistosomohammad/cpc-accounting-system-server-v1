<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Personnel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentAccount
{
    /**
     * Handle an incoming request.
     * Read X-Account-Id header, validate user has access, set current_account_id on request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->header('X-Account-Id');
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (!$accountId) {
            $request->attributes->set('current_account_id', null);
            return $next($request);
        }

        // Admins may set context to an inactive account (e.g. when managing it on Accounts page after toggling inactive)
        $query = Account::where('id', $accountId);
        if (!$user instanceof Admin) {
            $query->where('is_active', true);
        }
        $account = $query->first();
        if (!$account) {
            $request->attributes->set('current_account_id', null);
            return $next($request);
        }

        $hasAccess = false;
        if ($user instanceof Admin) {
            $hasAccess = $user->accounts()->where('accounts.id', $accountId)->exists();
        } elseif ($user instanceof Personnel) {
            $hasAccess = $user->accounts()->where('accounts.id', $accountId)->exists();
        }

        if (!$hasAccess) {
            $request->attributes->set('current_account_id', null);
            return $next($request);
        }

        $request->attributes->set('current_account_id', (int) $accountId);

        return $next($request);
    }
}
