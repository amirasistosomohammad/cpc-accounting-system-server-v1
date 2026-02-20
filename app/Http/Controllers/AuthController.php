<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Personnel;
use App\Services\ActivityLogService;
use App\Services\TimeLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login for Admin
     */
    public function adminLogin(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $request->username)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Create token with expiration from config
        $expirationMinutes = config('sanctum.expiration', 1440);
        $token = $admin->createToken('admin-token', ['*'], now()->addMinutes($expirationMinutes))->plainTextToken;

        $accounts = $admin->accounts()->where('is_active', true)->orderBy('name')->get();
        $currentAccount = $accounts->first();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'name' => $admin->name,
                'role' => 'admin',
            ],
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'code' => $a->code, 'logo' => $a->getLogoUrl()]),
            'current_account' => $currentAccount ? ['id' => $currentAccount->id, 'name' => $currentAccount->name, 'code' => $currentAccount->code, 'logo' => $currentAccount->getLogoUrl()] : null,
            'token' => $token,
        ], 200);
    }

    /**
     * Login for Personnel
     */
    public function personnelLogin(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $personnel = Personnel::where('username', $request->username)->first();

        if (!$personnel || !Hash::check($request->password, $personnel->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($personnel->is_active === false) {
            throw ValidationException::withMessages([
                'username' => ['Your account has been deactivated. Please contact an administrator.'],
            ]);
        }

        // Create token with expiration from config
        $expirationMinutes = config('sanctum.expiration', 1440);
        $token = $personnel->createToken('personnel-token', ['*'], now()->addMinutes($expirationMinutes))->plainTextToken;

        ActivityLogService::logLogin($personnel, $request);
        TimeLogService::recordLogin($personnel, $request);

        $sidebarAccess = $personnel->sidebar_access;
        if (!is_array($sidebarAccess)) {
            $sidebarAccess = ['dashboard', 'journal_entries', 'clients_ar', 'reports'];
        }

        $accounts = $personnel->accounts()->where('is_active', true)->orderBy('name')->get();
        $currentAccount = $accounts->first();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $personnel->id,
                'username' => $personnel->username,
                'name' => $personnel->name ?? trim(($personnel->first_name ?? '') . ' ' . ($personnel->last_name ?? '')),
                'role' => 'personnel',
                'sidebar_access' => $sidebarAccess,
            ],
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'code' => $a->code, 'logo' => $a->getLogoUrl()]),
            'current_account' => $currentAccount ? ['id' => $currentAccount->id, 'name' => $currentAccount->name, 'code' => $currentAccount->code, 'logo' => $currentAccount->getLogoUrl()] : null,
            'token' => $token,
        ], 200);
    }

    /**
     * Universal login - tries admin first, then personnel
     */
    public function login(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            // Try Admin first
            $admin = Admin::where('username', $request->username)->first();
            if ($admin && Hash::check($request->password, $admin->password)) {
                $expirationMinutes = config('sanctum.expiration', 1440);
                $token = $admin->createToken('admin-token', ['*'], now()->addMinutes($expirationMinutes))->plainTextToken;
                ActivityLogService::logLogin($admin, $request);

                $accounts = $admin->accounts()->where('is_active', true)->orderBy('name')->get();
                $currentAccount = $accounts->first();

                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => [
                        'id' => $admin->id,
                        'username' => $admin->username,
                        'name' => $admin->name,
                        'role' => 'admin',
                    ],
                    'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'code' => $a->code]),
                    'current_account' => $currentAccount ? ['id' => $currentAccount->id, 'name' => $currentAccount->name, 'code' => $currentAccount->code] : null,
                    'token' => $token,
                ], 200);
            }

            // Try Personnel
            $personnel = Personnel::where('username', $request->username)->first();
            if ($personnel && Hash::check($request->password, $personnel->password)) {
                if ($personnel->is_active === false) {
                    throw ValidationException::withMessages([
                        'username' => ['Your account has been deactivated. Please contact an administrator.'],
                    ]);
                }
                $expirationMinutes = config('sanctum.expiration', 1440);
                $token = $personnel->createToken('personnel-token', ['*'], now()->addMinutes($expirationMinutes))->plainTextToken;
                ActivityLogService::logLogin($personnel, $request);
                TimeLogService::recordLogin($personnel, $request);

                $sidebarAccess = $personnel->sidebar_access;
                if (!is_array($sidebarAccess)) {
                    $sidebarAccess = ['dashboard', 'journal_entries', 'clients_ar', 'reports'];
                }

                $accounts = $personnel->accounts()->where('is_active', true)->orderBy('name')->get();
                $currentAccount = $accounts->first();

                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => [
                        'id' => $personnel->id,
                        'username' => $personnel->username,
                        'name' => $personnel->name ?? trim(($personnel->first_name ?? '') . ' ' . ($personnel->last_name ?? '')),
                        'role' => 'personnel',
                        'sidebar_access' => $sidebarAccess,
                    ],
                    'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'code' => $a->code]),
                    'current_account' => $currentAccount ? ['id' => $currentAccount->id, 'name' => $currentAccount->name, 'code' => $currentAccount->code] : null,
                    'token' => $token,
                ], 200);
            }

            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during login. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Logout - revoke current token
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            if ($user instanceof Personnel) {
                TimeLogService::recordLogout($user, $request);
            }
            ActivityLogService::logLogout($user, $request);
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            // Reload from DB so we always return latest is_active and sidebar_access
            $user->refresh();

            // Inactive personnel must not use the app – treat as unauthenticated
            if ($user instanceof Personnel && $user->is_active === false) {
                $user->currentAccessToken()->delete();
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact an administrator.',
                ], 401);
            }

            $payload = [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user instanceof Personnel
                    ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                    : $user->name,
                'role' => $user instanceof Admin ? 'admin' : 'personnel',
            ];
            if ($user instanceof Personnel) {
                $sidebarAccess = $user->sidebar_access;
                $payload['sidebar_access'] = is_array($sidebarAccess) ? $sidebarAccess : ['dashboard', 'journal_entries', 'clients_ar', 'reports'];
                $payload['first_name'] = $user->first_name;
                $payload['last_name'] = $user->last_name;
                $payload['phone'] = $user->phone;
                $payload['created_at'] = $user->created_at?->toDateTimeString();
                $payload['updated_at'] = $user->updated_at?->toDateTimeString();
                $payload['avatar_path'] = $user->avatar_path;
            }

            $accounts = $user->accounts()->where('is_active', true)->orderBy('name')->get();
            $currentAccount = $accounts->first();

            return response()->json([
                'success' => true,
                'user' => $payload,
                'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'code' => $a->code, 'logo' => $a->getLogoUrl(), 'is_active' => $a->is_active]),
                'current_account' => $currentAccount ? ['id' => $currentAccount->id, 'name' => $currentAccount->name, 'code' => $currentAccount->code, 'logo' => $currentAccount->getLogoUrl()] : null,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get user error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user information',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get current user's full profile (for Personnel Profile page – same shape as admin personnel show).
     * Personnel get full record including avatar_path, phone, created_at, updated_at.
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        if ($user instanceof Personnel) {
            $personnel = Personnel::find($user->id);
            if (!$personnel) {
                return response()->json(['success' => false, 'message' => 'Personnel not found'], 404);
            }
            $personnel->created_by_name = ActivityLogService::resolveNameFromTypeId($personnel->created_by_type, $personnel->created_by_id);
            $personnel->updated_by_name = ActivityLogService::resolveNameFromTypeId($personnel->updated_by_type, $personnel->updated_by_id);
            return response()->json(['success' => true, 'personnel' => $personnel], 200);
        }
        return response()->json(['success' => true, 'user' => $user], 200);
    }

    /**
     * Update profile (change password). Admin and Personnel.
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ], [
            'new_password.confirmed' => 'The new password confirmation does not match.',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->password = $request->new_password;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ], 200);
    }
}
