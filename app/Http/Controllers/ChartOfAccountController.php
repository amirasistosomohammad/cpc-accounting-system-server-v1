<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    /**
     * Lightweight COA list: one raw DB query, no Eloquent/scopes. Use this for Business Accounts
     * to avoid 504 (gateway timeout → no CORS header → "CORS error"). Same auth + SetCurrentAccount.
     */
    public function indexList(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        if ($accountId === null || $accountId === '') {
            return response()->json([]);
        }

        $activeOnly = $request->boolean('active_only', false);
        $category = $request->has('category') && in_array($request->category, ['expense', 'revenue'], true) ? $request->category : null;
        $query = DB::table('chart_of_accounts')
            ->leftJoin('account_types', 'chart_of_accounts.account_type_id', '=', 'account_types.id')
            ->where('chart_of_accounts.account_id', $accountId)
            ->orderBy('chart_of_accounts.account_code')
            ->select(
                'chart_of_accounts.id',
                'chart_of_accounts.account_code',
                'chart_of_accounts.account_name',
                'chart_of_accounts.account_type_id',
                'chart_of_accounts.normal_balance',
                'chart_of_accounts.is_active',
                'chart_of_accounts.description',
                'account_types.code as account_type',
                'account_types.category as account_type_category'
            );
        if ($activeOnly) {
            $query->where('chart_of_accounts.is_active', true);
        }
        if ($category !== null) {
            $query->where('account_types.category', $category);
        }
        $rows = $query->get();
        $coaIds = $rows->pluck('id')->all();

        // Compute balances from journal_entry_lines so COA table reflects transactions (same logic as index()).
        $balances = [];
        if (!empty($coaIds)) {
            $balanceRows = DB::table('journal_entry_lines')
                ->selectRaw('account_id, COALESCE(SUM(debit_amount),0) as debits, COALESCE(SUM(credit_amount),0) as credits')
                ->whereIn('account_id', $coaIds)
                ->groupBy('account_id')
                ->get();
            foreach ($balanceRows as $br) {
                $balances[(int) $br->account_id] = [(float) $br->debits, (float) $br->credits];
            }
        }

        $list = [];
        foreach ($rows as $row) {
            $d = $balances[(int) $row->id][0] ?? 0;
            $c = $balances[(int) $row->id][1] ?? 0;
            $balance = $row->normal_balance === 'DR' ? $d - $c : $c - $d;

            $list[] = [
                'id' => (int) $row->id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type_id' => (int) $row->account_type_id,
                'normal_balance' => $row->normal_balance,
                'is_active' => (bool) $row->is_active,
                'description' => $row->description,
                'balance' => $balance,
                'account_type' => $row->account_type,
                'account_type_category' => $row->account_type_category,
            ];
        }
        return response()->json($list);
    }

    /**
     * Get all chart of accounts. Balances loaded in one query to avoid 504 on production.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ChartOfAccount::with('accountType');

        // Filter by account type if provided (account_type_id, account_type code, or category)
        $accountId = $request->attributes->get('current_account_id');
        if ($request->has('account_type_id')) {
            $query->where('account_type_id', $request->account_type_id);
        } elseif ($request->has('category') && in_array($request->category, ['expense', 'revenue'], true)) {
            $category = $request->category;
            $query->whereHas('accountType', function ($q) use ($category, $accountId) {
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
            $accountType = \App\Models\AccountType::where('account_id', $accountId)
                ->where('code', $request->account_type)
                ->first();
            if ($accountType) {
                $query->where('account_type_id', $accountType->id);
            }
        }

        if ($request->boolean('active_only', true)) {
            $query->where('chart_of_accounts.is_active', true);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('account_code', 'like', "%{$search}%")
                    ->orWhere('account_name', 'like', "%{$search}%");
            });
        }

        $accounts = $query->orderBy('account_code')->get();

        // Skip balance query when no_balance=1 so response is fast and never 504 (gateway timeout = no CORS header = "CORS error")
        if (!$request->boolean('no_balance', false)) {
            $coaIds = $accounts->pluck('id')->all();
            $balances = [];
            if (!empty($coaIds)) {
                $rows = DB::table('journal_entry_lines')
                    ->selectRaw('account_id, COALESCE(SUM(debit_amount),0) as debits, COALESCE(SUM(credit_amount),0) as credits')
                    ->whereIn('account_id', $coaIds)
                    ->groupBy('account_id')
                    ->get();
                foreach ($rows as $row) {
                    $balances[(int) $row->account_id] = [(float) $row->debits, (float) $row->credits];
                }
            }
            foreach ($accounts as $account) {
                $d = $balances[$account->id][0] ?? 0;
                $c = $balances[$account->id][1] ?? 0;
                $balance = $account->normal_balance === 'DR' ? $d - $c : $c - $d;
                $account->setAttribute('balance', $balance);
            }
        } else {
            foreach ($accounts as $account) {
                $account->setAttribute('balance', 0);
            }
        }

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


