<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $admins = Admin::orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'admins' => $admins,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch admins',
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
            $validator = Validator::make($request->all(), [
                'username' => 'required|string|unique:admins,username|max:255',
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $admin = new Admin();
            $admin->username = $request->username;
            $admin->name = $request->name;
            $admin->password = $request->password; // Will be hashed automatically by model

            $admin->save();

            return response()->json([
                'success' => true,
                'message' => 'Admin created successfully',
                'admin' => $admin,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $admin = Admin::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'admin' => $admin,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin not found',
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
            $admin = Admin::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'username' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('admins')->ignore($id),
                ],
                'name' => 'required|string|max:255',
                'password' => 'nullable|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $admin->username = $request->username;
            $admin->name = $request->name;

            // Update password if provided
            if ($request->filled('password')) {
                $admin->password = $request->password; // Will be hashed automatically
            }

            $admin->save();

            return response()->json([
                'success' => true,
                'message' => 'Admin updated successfully',
                'admin' => $admin,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update admin',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        try {
            $admin = Admin::findOrFail($id);

            // Prevent deleting the last admin
            $adminCount = Admin::count();
            if ($adminCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete the last admin. At least one admin must exist.',
                ], 422);
            }

            // Prevent deleting yourself
            $currentAdmin = $request->user();
            if ($currentAdmin && $currentAdmin instanceof \App\Models\Admin && (int)$id === (int)$currentAdmin->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account.',
                ], 422);
            }

            $admin->delete();

            return response()->json([
                'success' => true,
                'message' => 'Admin deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete admin',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

