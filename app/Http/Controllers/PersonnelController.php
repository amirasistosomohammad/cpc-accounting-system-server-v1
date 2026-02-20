<?php

namespace App\Http\Controllers;

use App\Models\Personnel;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PersonnelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $personnel = Personnel::orderBy('created_at', 'desc')->get();

            // Append footprint for view modal (no extra fetch needed)
            $personnel->transform(function ($person) {
                $person->created_by_name = ActivityLogService::resolveNameFromTypeId($person->created_by_type, $person->created_by_id);
                $person->updated_by_name = ActivityLogService::resolveNameFromTypeId($person->updated_by_type, $person->updated_by_id);
                return $person;
            });

            return response()->json([
                'success' => true,
                'personnel' => $personnel,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch personnel',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // Normalize sidebar_access when sent as JSON string (FormData)
            if ($request->has('sidebar_access') && is_string($request->input('sidebar_access'))) {
                $decoded = json_decode($request->input('sidebar_access'), true);
                $request->merge(['sidebar_access' => is_array($decoded) ? $decoded : []]);
            }

            $validator = Validator::make($request->all(), [
                'username' => 'required|string|unique:personnel,username|max:255',
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:20',
                'password' => 'required|string|min:8|confirmed',
                'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'is_active' => 'nullable|boolean',
                'sidebar_access' => 'nullable|array',
                'sidebar_access.*' => 'string|in:dashboard,journal_entries,cash_bank,clients_ar,suppliers_ap,income,expenses,reports',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $personnel = new Personnel();
            $personnel->username = $request->username;
            $personnel->first_name = $request->first_name;
            $personnel->last_name = $request->last_name;
            $personnel->phone = $request->phone;
            $personnel->password = $request->password; // Will be hashed automatically by model
            $personnel->is_active = $request->has('is_active')
                ? filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)
                : true;

            $sidebarAccess = $request->input('sidebar_access');
            if (is_array($sidebarAccess)) {
                $personnel->sidebar_access = array_values(array_unique(array_filter($sidebarAccess)));
            } else {
                $personnel->sidebar_access = ['dashboard', 'journal_entries', 'clients_ar', 'reports'];
            }

            $actor = ActivityLogService::getUserTypeAndId($request->user());
            $personnel->created_by_type = $actor['user_type'];
            $personnel->created_by_id = $actor['user_id'];

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                $avatar = $request->file('avatar');
                $filename = time() . '_' . uniqid() . '.' . $avatar->getClientOriginalExtension();
                $path = $avatar->storeAs('personnel-avatars', $filename, 'public');
                $personnel->avatar_path = $path;
            }

            $personnel->save();

            return response()->json([
                'success' => true,
                'message' => 'Personnel created successfully',
                'personnel' => $personnel,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create personnel',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     * Admin can view any personnel; personnel can only view their own record (for Profile page).
     */
    public function show(Request $request, string $id)
    {
        try {
            $authUser = $request->user();
            if ($authUser instanceof Personnel && (string) $authUser->id !== (string) $id) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $personnel = Personnel::findOrFail($id);
            $personnel->created_by_name = ActivityLogService::resolveNameFromTypeId($personnel->created_by_type, $personnel->created_by_id);
            $personnel->updated_by_name = ActivityLogService::resolveNameFromTypeId($personnel->updated_by_type, $personnel->updated_by_id);

            return response()->json([
                'success' => true,
                'personnel' => $personnel,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Personnel not found',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $personnel = Personnel::findOrFail($id);

            // Normalize sidebar_access when sent as JSON string (FormData with PUT/multipart)
            if ($request->has('sidebar_access') && is_string($request->input('sidebar_access'))) {
                $decoded = json_decode($request->input('sidebar_access'), true);
                $request->merge(['sidebar_access' => is_array($decoded) ? $decoded : []]);
            }

            $validator = Validator::make($request->all(), [
                'username' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('personnel')->ignore($id),
                ],
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:20',
                'password' => 'nullable|string|min:8|confirmed',
                'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'remove_avatar' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                'sidebar_access' => 'nullable|array',
                'sidebar_access.*' => 'string|in:dashboard,journal_entries,cash_bank,clients_ar,suppliers_ap,income,expenses,reports',
                'account_ids' => 'nullable|array',
                'account_ids.*' => 'integer|exists:accounts,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $personnel->username = $request->username;
            $personnel->first_name = $request->first_name;
            $personnel->last_name = $request->last_name;
            $personnel->phone = $request->phone;

            // Always apply is_active when present (handle "0"/"1" from FormData)
            if ($request->has('is_active')) {
                $personnel->is_active = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
            }

            // Always apply sidebar_access when present (array or already normalized from JSON)
            if ($request->has('sidebar_access')) {
                $access = $request->input('sidebar_access');
                $personnel->sidebar_access = is_array($access)
                    ? array_values(array_unique(array_filter($access)))
                    : [];
            }

            // Update password if provided
            if ($request->filled('password')) {
                $personnel->password = $request->password; // Will be hashed automatically
            }

            // Handle avatar removal
            if ($request->has('remove_avatar') && $request->remove_avatar == '1') {
                if ($personnel->avatar_path) {
                    Storage::disk('public')->delete($personnel->avatar_path);
                    $personnel->avatar_path = null;
                }
            }

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                // Delete old avatar if exists
                if ($personnel->avatar_path) {
                    Storage::disk('public')->delete($personnel->avatar_path);
                }

                $avatar = $request->file('avatar');
                $filename = time() . '_' . uniqid() . '.' . $avatar->getClientOriginalExtension();
                $path = $avatar->storeAs('personnel-avatars', $filename, 'public');
                $personnel->avatar_path = $path;
            }

            $actor = ActivityLogService::getUserTypeAndId($request->user());
            $personnel->updated_by_type = $actor['user_type'];
            $personnel->updated_by_id = $actor['user_id'];

            $personnel->save();

            // Sync accounts if account_ids provided
            if ($request->has('account_ids') && is_array($request->account_ids)) {
                $personnel->accounts()->sync($request->account_ids);
            }

            return response()->json([
                'success' => true,
                'message' => 'Personnel updated successfully',
                'personnel' => $personnel,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update personnel',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $personnel = Personnel::findOrFail($id);

            // Delete avatar if exists
            if ($personnel->avatar_path) {
                Storage::disk('public')->delete($personnel->avatar_path);
            }

            $personnel->delete();

            return response()->json([
                'success' => true,
                'message' => 'Personnel deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete personnel',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
