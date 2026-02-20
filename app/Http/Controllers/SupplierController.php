<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Supplier;
use App\Services\ActivityLogService;
use App\Services\AuthorizationCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    /**
     * Get all suppliers
     */
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::with('bills');

        // Search filter
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Active filter
        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        $suppliers = $query->orderBy('name')->get();

        // Calculate total payable and append footprint for view modal
        $suppliers->each(function ($supplier) {
            $supplier->total_payable = $supplier->total_payable;
            $supplier->created_by_name = ActivityLogService::resolveNameFromTypeId($supplier->created_by_type, $supplier->created_by_id);
            $supplier->updated_by_name = ActivityLogService::resolveNameFromTypeId($supplier->updated_by_type, $supplier->updated_by_id);
        });

        return response()->json($suppliers);
    }

    /**
     * Get a single supplier
     */
    public function show($id): JsonResponse
    {
        $supplier = Supplier::with('bills.expenseAccount')->findOrFail($id);
        $supplier->total_payable = $supplier->total_payable;
        $supplier->created_by_name = ActivityLogService::resolveNameFromTypeId($supplier->created_by_type, $supplier->created_by_id);
        $supplier->updated_by_name = ActivityLogService::resolveNameFromTypeId($supplier->updated_by_type, $supplier->updated_by_id);

        return response()->json($supplier);
    }

    /**
     * Create a new supplier
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $footprint = ActivityLogService::getUserTypeAndId($request->user());
        $supplier = Supplier::create(array_merge($validated, [
            'created_by_type' => $footprint['user_type'],
            'created_by_id' => $footprint['user_id'],
        ]));

        ActivityLogService::log('created', $request->user(), Supplier::class, $supplier->id, null, $supplier->toArray(), null, null, $request);
        return response()->json($supplier, 201);
    }

    /**
     * Update a supplier
     */
    public function update(Request $request, $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $oldValues = $supplier->toArray();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $footprint = ActivityLogService::getUserTypeAndId($request->user());
        $supplier->update(array_merge($validated, [
            'updated_by_type' => $footprint['user_type'],
            'updated_by_id' => $footprint['user_id'],
        ]));

        ActivityLogService::log('updated', $request->user(), Supplier::class, $supplier->id, $oldValues, $supplier->toArray(), null, null, $request);
        return response()->json($supplier);
    }

    /**
     * Delete a supplier. Personnel must provide a valid authorization_code for delete_supplier.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $user = $request->user();
        $authCodeId = null;

        // Check if supplier has bills
        if ($supplier->bills()->exists()) {
            // Soft delete by setting is_active to false (no auth code required)
            $supplier->update(['is_active' => false]);
            ActivityLogService::log('updated', $user, Supplier::class, $supplier->id, null, ['is_active' => false], null, 'Deactivated (has bills)', $request);
            return response()->json([
                'message' => 'Supplier deactivated (has bills)',
                'supplier' => $supplier
            ]);
        }

        // Admin does not need authorization code; personnel must provide it
        if (!$user instanceof Admin) {
            $code = $request->input('authorization_code');
            if (!$code) {
                throw ValidationException::withMessages(['authorization_code' => ['This action requires an authorization code from your admin.']]);
            }
            $codeModel = AuthorizationCodeService::validateAndUse($code, 'delete_supplier', $user, Supplier::class, $supplier->id);
            $authCodeId = $codeModel->id;
        }

        $oldValues = $supplier->toArray();
        $supplier->delete();
        ActivityLogService::log('deleted', $user, Supplier::class, (int) $id, $oldValues, null, $authCodeId, $request->input('remarks'), $request);

        return response()->json(['message' => 'Supplier deleted successfully']);
    }
}
