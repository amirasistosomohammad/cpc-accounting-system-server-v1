<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\AuthorizationCode;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AuthorizationCodeController extends Controller
{
    /**
     * List authorization codes. Admin only. Returns all codes for Settings UI.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = AuthorizationCode::query()->orderBy('created_at', 'desc');
        if ($request->filled('for_action')) {
            $query->where('for_action', $request->for_action);
        }
        $perPage = min((int) $request->get('per_page', 100), 500);
        $items = $query->paginate($perPage);

        return response()->json([
            'codes' => $items->items(),
            'data' => $items->items(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /**
     * Create authorization code. Admin only. Manual create (code, description, expires_at, is_active).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'code' => 'required|string|max:64',
            'description' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $codeStr = strtoupper(trim($validated['code']));
        if (AuthorizationCode::where('code', $codeStr)->exists()) {
            throw ValidationException::withMessages(['code' => ['This code already exists.']]);
        }

        // If no expiry date is provided, treat it as "no expiration" (null).
        $expiresAt = isset($validated['expires_at']) && $validated['expires_at']
            ? \Carbon\Carbon::parse($validated['expires_at'])
            : null;

        $code = AuthorizationCode::create([
            'code' => $codeStr,
            'admin_type' => 'admin',
            'admin_id' => $user->id,
            'for_action' => 'manual',
            'description' => $validated['description'] ?? null,
            'expires_at' => $expiresAt,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'id' => $code->id,
            'code' => $code->code,
            'description' => $code->description,
            'expires_at' => $code->expires_at ? $code->expires_at->toIso8601String() : null,
            'is_active' => $code->is_active,
        ], 201);
    }

    /**
     * Update authorization code. Admin only.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $code = AuthorizationCode::findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|string|max:64',
            'description' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($validated['code'])) {
            $codeStr = strtoupper(trim($validated['code']));
            if (AuthorizationCode::where('code', $codeStr)->where('id', '!=', $id)->exists()) {
                throw ValidationException::withMessages(['code' => ['This code already exists.']]);
            }
            $code->code = $codeStr;
        }
        if (array_key_exists('description', $validated)) {
            $code->description = $validated['description'];
        }
        if (array_key_exists('expires_at', $validated)) {
            // If expires_at is explicitly null/empty, clear the expiry (no expiration).
            $code->expires_at = $validated['expires_at']
                ? \Carbon\Carbon::parse($validated['expires_at'])
                : null;
        }
        if (array_key_exists('is_active', $validated)) {
            $code->is_active = (bool) $validated['is_active'];
        }
        $code->save();

        return response()->json([
            'success' => true,
            'code' => $code->code,
            'description' => $code->description,
            'expires_at' => $code->expires_at ? $code->expires_at->toIso8601String() : null,
            'is_active' => $code->is_active,
        ]);
    }

    /**
     * Delete authorization code. Admin only.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $code = AuthorizationCode::findOrFail($id);
        $code->delete();

        return response()->json(['success' => true, 'message' => 'Authorization code deleted.']);
    }

    /**
     * Validate a code for an action (used by frontend or by other controllers before performing sensitive action).
     * Returns the code model if valid; 422 if invalid/expired/used.
     */
    public function validateCode(Request $request): JsonResponse
    {
        $request->validate([
            // Universal codes: only the code itself is required. for_action is optional
            // and no longer restricts which actions a code may be used for.
            'code' => 'required|string|max:64',
            'for_action' => 'sometimes|string|max:64',
        ]);

        $codeModel = AuthorizationCode::where('code', strtoupper($request->code))->first();
        if (!$codeModel) {
            throw ValidationException::withMessages(['code' => ['The authorization code is invalid.']]);
        }
        // NOTE: We intentionally DO NOT check $codeModel->for_action here anymore.
        // Codes are universal and may be used for any protected action as long
        // as they are active and not expired.
        if (!$codeModel->isValid()) {
            throw ValidationException::withMessages(['code' => ['The authorization code has expired or has already been used.']]);
        }

        return response()->json([
            'valid' => true,
            'expires_at' => $codeModel->expires_at ? $codeModel->expires_at->toIso8601String() : null,
        ]);
    }

    /**
     * Lightweight check: are there any active, non-expired manual authorization codes?
     * Used by personnel side to decide whether to show the Authorization Required modal.
     */
    public function hasActiveCodes(Request $request): JsonResponse
    {
        $now = now();

        $hasCodes = AuthorizationCode::query()
            ->where('for_action', 'manual')
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->exists();

        return response()->json([
            'has_codes' => $hasCodes,
        ]);
    }
}
