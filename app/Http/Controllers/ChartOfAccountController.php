<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    /**
     * Get all chart of accounts
     */
    public function index(Request $request): JsonResponse
    {
        $query = ChartOfAccount::with('accountType');

        // Filter by account type if provided (account_type_id, account_type code, or category)
        $accountId = $request->attributes->get('current_account_id');
        if ($request->has('account_type_id')) {
            $query->where('account_type_id', $request->account_type_id);
        } elseif ($request->has('category') && in_array($request->category, ['expense', 'revenue'], true)) {
            // Fully dynamic: filter by account_types.category only (no hardcoded type codes/IDs).
            // Any chart of account whose account type has this category is included.
            $category = $request->category;
            $query->whereHas('accountType', function ($q) use ($category, $accountId) {
                // Restrict to current business account types (tenant isolation)
                if ($accountId !== null && $accountId !== '') {
                    $q->where('account_types.account_id', $accountId);
                }
                if ($category === 'expense') {
                    $q->where('account_types.category', 'expense');
                } else {
                    $q->where('account_types.category', 'revenue');
                }
            });
        } elseif ($request->has('account_type') && $accountId) {
            // Look up type by code for the current business only (legacy)
            $accountType = \App\Models\AccountType::where('account_id', $accountId)
                ->where('code', $request->account_type)
                ->first();
            if ($accountType) {
                $query->where('account_type_id', $accountType->id);
            }
        }

        // Filter active only if requested (qualify column: both chart_of_accounts and account_types have is_active when joined)
        if ($request->boolean('active_only', true)) {
            $query->where('chart_of_accounts.is_active', true);
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('account_code', 'like', "%{$search}%")
                    ->orWhere('account_name', 'like', "%{$search}%");
            });
        }

        $accounts = $query->orderBy('account_code')->get();

        // Ensure balance and account_type (from accessor) are included when serialized
        $accounts->each(function ($account) {
            $account->setAttribute('balance', $account->balance);
        });

        return response()->json($accounts);
    }

    /**
     * Get a single chart of account
     */
    public function show($id): JsonResponse
    {
        $account = ChartOfAccount::with('accountType')->findOrFail($id);
        $account->setAttribute('balance', $account->balance);

        return response()->json($account);
    }

    /**
     * Create a new chart of account
     */
    public function store(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        
        // Support both account_type_id and account_type (code) for backward compatibility; type must belong to current account
        $accountTypeId = $request->input('account_type_id');
        if (!$accountTypeId && $request->has('account_type')) {
            $accountType = \App\Models\AccountType::where('account_id', $accountId)->where('code', $request->account_type)->first();
            if ($accountType) {
                $accountTypeId = $accountType->id;
            }
        }

        $validated = $request->validate([
            'account_code' => [
                'required',
                'string',
                'max:10',
                Rule::unique('chart_of_accounts', 'account_code')->where('account_id', $accountId),
            ],
            'account_name' => 'required|string|max:255',
            'account_type_id' => ['required', Rule::exists('account_types', 'id')->where('account_id', $accountId)],
            'account_type' => ['sometimes'], // Legacy support
            'normal_balance' => 'required|in:DR,CR',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Use account_type_id from validation or from lookup
        $validated['account_type_id'] = $accountTypeId;
        unset($validated['account_type']); // Remove legacy field

        // Default to active so new accounts appear in expense/income dropdowns
        if (!array_key_exists('is_active', $validated)) {
            $validated['is_active'] = true;
        }

        $account = ChartOfAccount::create($validated);
        $account->load('accountType');

        return response()->json($account, 201);
    }

    /**
     * Update a chart of account
     */
    public function update(Request $request, $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $accountId = $request->attributes->get('current_account_id');
        
        // Support both account_type_id and account_type (code) for backward compatibility; type must belong to current account
        $accountTypeId = $request->input('account_type_id');
        if (!$accountTypeId && $request->has('account_type')) {
            $accountType = \App\Models\AccountType::where('account_id', $accountId)->where('code', $request->account_type)->first();
            if ($accountType) {
                $accountTypeId = $accountType->id;
            }
        }

        $validated = $request->validate([
            'account_code' => [
                'required',
                'string',
                'max:10',
                Rule::unique('chart_of_accounts', 'account_code')->where('account_id', $accountId)->ignore($account->id),
            ],
            'account_name' => 'required|string|max:255',
            'account_type_id' => ['required', Rule::exists('account_types', 'id')->where('account_id', $accountId)],
            'account_type' => ['sometimes'], // Legacy support
            'normal_balance' => 'required|in:DR,CR',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Use account_type_id from validation or from lookup
        $validated['account_type_id'] = $accountTypeId;
        unset($validated['account_type']); // Remove legacy field

        $account->update($validated);
        $account->load('accountType');

        return response()->json($account);
    }

    /**
     * Delete a chart of account (soft delete by setting is_active to false)
     */
    public function destroy($id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);

        // Check if account has transactions
        $hasTransactions = $account->journalEntryLines()->exists();

        if ($hasTransactions) {
            // Soft delete by setting is_active to false
            $account->update(['is_active' => false]);
            return response()->json([
                'message' => 'Account deactivated (has transactions)',
                'account' => $account
            ]);
        }

        // Hard delete if no transactions
        $account->delete();

        return response()->json(['message' => 'Account deleted successfully']);
    }
}


