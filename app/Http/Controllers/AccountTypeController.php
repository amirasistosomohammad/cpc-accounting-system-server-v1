<?php

namespace App\Http\Controllers;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountTypeController extends Controller
{
    /**
     * Display a listing of account types for the current business account
     */
    public function index(Request $request)
    {
        $accountId = $request->attributes->get('current_account_id');

        if ($accountId === null || $accountId === '') {
            return response()->json([
                'message' => 'Account context required. Please select a business account.',
            ], 422);
        }

        $accountTypes = AccountType::where('account_id', $accountId)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json($accountTypes);
    }

    /**
     * Store a newly created account type for the current business account
     */
    public function store(Request $request)
    {
        $accountId = $request->attributes->get('current_account_id');

        if ($accountId === null || $accountId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Account context required. Please select a business account.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('account_types', 'code')->where('account_id', $accountId)],
            'name' => ['required', 'string', 'max:255', Rule::unique('account_types', 'name')->where('account_id', $accountId)],
            'normal_balance' => ['required', 'in:DR,CR'],
            'category' => ['nullable', 'string', 'in:asset,liability,equity,revenue,expense'],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['account_id'] = $accountId;

        if (empty($data['category'])) {
            $data['category'] = 'expense';
        }
        if (!array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }

        // If no code provided, derive a professional code from the name (unique per account)
        $code = $data['code'] ?? null;
        if (!$code) {
            $base = Str::upper(preg_replace('/[^A-Z0-9]+/', '_', Str::slug($data['name'])));
            if (!$base) {
                $base = 'TYPE_' . Str::upper(Str::random(4));
            }
            $code = $base;
            $counter = 1;
            while (AccountType::where('account_id', $accountId)->where('code', $code)->exists()) {
                $code = $base . '_' . $counter;
                $counter++;
            }
        }
        $data['code'] = $code;

        $accountType = AccountType::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Account type created successfully',
            'account_type' => $accountType,
        ], 201);
    }

    /**
     * Display the specified account type (must belong to current account)
     */
    public function show(Request $request, $id)
    {
        $accountId = $request->attributes->get('current_account_id');
        $accountType = AccountType::where('id', $id)->where('account_id', $accountId)->first();

        if (!$accountType) {
            return response()->json([
                'success' => false,
                'message' => 'Account type not found',
            ], 404);
        }

        return response()->json($accountType);
    }

    /**
     * Update the specified account type (must belong to current account)
     */
    public function update(Request $request, $id)
    {
        $accountId = $request->attributes->get('current_account_id');
        $accountType = AccountType::where('id', $id)->where('account_id', $accountId)->first();

        if (!$accountType) {
            return response()->json([
                'success' => false,
                'message' => 'Account type not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('account_types', 'code')->where('account_id', $accountId)->ignore($id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('account_types', 'name')->where('account_id', $accountId)->ignore($id)],
            'normal_balance' => ['required', 'in:DR,CR'],
            'category' => ['nullable', 'string', 'in:asset,liability,equity,revenue,expense'],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (!array_key_exists('is_active', $data)) {
            $data['is_active'] = $accountType->is_active;
        }

        if (!array_key_exists('code', $data) || $data['code'] === null || $data['code'] === '') {
            $data['code'] = $accountType->code;
        }

        $accountType->update($data);

        // Cascade normal_balance to all chart-of-accounts using this type so the COA table reflects the change.
        if (array_key_exists('normal_balance', $data)) {
            ChartOfAccount::where('account_type_id', $accountType->id)->update(['normal_balance' => $data['normal_balance']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Account type updated successfully',
            'account_type' => $accountType->fresh(),
        ]);
    }

    /**
     * Remove the specified account type (must belong to current account)
     */
    public function destroy(Request $request, $id)
    {
        $accountId = $request->attributes->get('current_account_id');
        $accountType = AccountType::where('id', $id)->where('account_id', $accountId)->first();

        if (!$accountType) {
            return response()->json([
                'success' => false,
                'message' => 'Account type not found',
            ], 404);
        }

        // Only count chart of accounts that belong to this same business
        $chartOfAccountsCount = $accountType->chartOfAccounts()->where('account_id', $accountId)->count();

        if ($chartOfAccountsCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete account type. It is being used by {$chartOfAccountsCount} chart of account(s).",
            ], 422);
        }

        $accountType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account type deleted successfully',
        ]);
    }
}
