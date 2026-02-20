<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CashBankController extends Controller
{
    /**
     * Get cash and bank accounts with transactions
     */
    public function index(Request $request): JsonResponse
    {
        // Get cash accounts (1010, 1020, 1030)
        $cashAccounts = ChartOfAccount::whereIn('account_code', ['1010', '1020', '1030'])
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get();

        // Get date range
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $cashAccounts->each(function ($account) use ($startDate, $endDate) {
            // Get all journal entry lines for this account
            $query = JournalEntryLine::where('account_id', $account->id)
                ->with(['journalEntry']);

            if ($startDate) {
                $query->whereHas('journalEntry', function ($q) use ($startDate) {
                    $q->where('entry_date', '>=', $startDate);
                });
            }

            if ($endDate) {
                $query->whereHas('journalEntry', function ($q) use ($endDate) {
                    $q->where('entry_date', '<=', $endDate);
                });
            }

            $lines = $query->orderBy('created_at')->get();

            // Calculate running balance
            $balance = 0;
            $transactions = $lines->map(function ($line) use (&$balance, $account) {
                if ($account->normal_balance === 'DR') {
                    $balance += $line->debit_amount - $line->credit_amount;
                } else {
                    $balance += $line->credit_amount - $line->debit_amount;
                }

                return [
                    'id' => $line->id,
                    'date' => $line->journalEntry->entry_date,
                    'entry_number' => $line->journalEntry->entry_number,
                    'description' => $line->description ?? $line->journalEntry->description,
                    'reference' => $line->journalEntry->reference_number,
                    'debit' => $line->debit_amount,
                    'credit' => $line->credit_amount,
                    'balance' => $balance,
                ];
            });

            $account->transactions = $transactions;
            $account->current_balance = $balance;
            $account->opening_balance = 0; // Can be enhanced later
        });

        return response()->json($cashAccounts);
    }

    /**
     * Get transactions for a specific cash account
     */
    public function show($id, Request $request): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);

        // Verify it's a cash account
        if (!in_array($account->account_code, ['1010', '1020', '1030'])) {
            return response()->json(['message' => 'Account is not a cash/bank account'], 422);
        }

        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = JournalEntryLine::where('account_id', $account->id)
            ->with(['journalEntry']);

        if ($startDate) {
            $query->whereHas('journalEntry', function ($q) use ($startDate) {
                $q->where('entry_date', '>=', $startDate);
            });
        }

        if ($endDate) {
            $query->whereHas('journalEntry', function ($q) use ($endDate) {
                $q->where('entry_date', '<=', $endDate);
            });
        }

        $lines = $query->orderBy('created_at')->paginate($request->get('per_page', 50));

        // Calculate running balance
        $balance = 0;
        $transactions = $lines->getCollection()->map(function ($line) use (&$balance, $account) {
            if ($account->normal_balance === 'DR') {
                $balance += $line->debit_amount - $line->credit_amount;
            } else {
                $balance += $line->credit_amount - $line->debit_amount;
            }

            return [
                'id' => $line->id,
                'date' => $line->journalEntry->entry_date,
                'entry_number' => $line->journalEntry->entry_number,
                'description' => $line->description ?? $line->journalEntry->description,
                'reference' => $line->journalEntry->reference_number,
                'debit' => $line->debit_amount,
                'credit' => $line->credit_amount,
                'balance' => $balance,
            ];
        });

        $lines->setCollection($transactions);

        return response()->json([
            'account' => $account,
            'transactions' => $lines,
            'current_balance' => $balance,
        ]);
    }
}


