<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Bill;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\ActivityLogService;
use App\Services\AuthorizationCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillController extends Controller
{
    /**
     * Get all bills
     */
    public function index(Request $request): JsonResponse
    {
        $query = Bill::with(['supplier', 'expenseAccount']);

        // Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Date range filter
        if ($request->has('start_date')) {
            $query->where('bill_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('bill_date', '<=', $request->end_date);
        }

        $bills = $query->orderBy('bill_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 15));

        // Append footprint for view modal (no extra fetch needed)
        $bills->getCollection()->transform(function ($bill) {
            $bill->created_by_name = ActivityLogService::resolveNameFromTypeId($bill->created_by_type, $bill->created_by_id);
            $bill->updated_by_name = ActivityLogService::resolveNameFromTypeId($bill->updated_by_type, $bill->updated_by_id);
            return $bill;
        });

        return response()->json($bills);
    }

    /**
     * Get a single bill
     */
    public function show($id): JsonResponse
    {
        $bill = Bill::with(['supplier', 'expenseAccount', 'payments.cashAccount'])->findOrFail($id);
        $bill->created_by_name = ActivityLogService::resolveNameFromTypeId($bill->created_by_type, $bill->created_by_id);
        $bill->updated_by_name = ActivityLogService::resolveNameFromTypeId($bill->updated_by_type, $bill->updated_by_id);

        return response()->json($bill);
    }

    /**
     * Create a new bill
     */
    public function store(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        $validated = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('account_id', $accountId)],
            'bill_date' => 'required|date',
            'due_date' => 'nullable|date',
            'expense_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
            'total_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();
            $footprint = ActivityLogService::getUserTypeAndId($request->user());

            // Get AP account (2010)
            $apAccount = \App\Models\ChartOfAccount::where('account_code', '2010')->first();
            if (!$apAccount) {
                throw new \Exception('Accounts Payable account (2010) not found in Chart of Accounts');
            }

            // Create bill
            $bill = Bill::create([
                'bill_number' => Bill::generateBillNumber(),
                'supplier_id' => $validated['supplier_id'],
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'] ?? null,
                'expense_account_id' => $validated['expense_account_id'],
                'total_amount' => $validated['total_amount'],
                'paid_amount' => 0,
                'balance' => $validated['total_amount'],
                'description' => $validated['description'] ?? null,
                'status' => 'draft',
                'created_by_type' => $footprint['user_type'],
                'created_by_id' => $footprint['user_id'],
            ]);

            // Create journal entry
            $journalEntry = JournalEntry::create([
                'entry_number' => JournalEntry::generateEntryNumber(),
                'entry_date' => $validated['bill_date'],
                'description' => "Bill {$bill->bill_number} - " . ($validated['description'] ?? 'Bill received'),
                'reference_number' => $bill->bill_number,
                'total_debit' => $validated['total_amount'],
                'total_credit' => $validated['total_amount'],
                'created_by' => $request->user()->id ?? null,
                'created_by_type' => $footprint['user_type'],
            ]);

            // Create journal entry lines
            // DR: Expense Account
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $validated['expense_account_id'],
                'debit_amount' => $validated['total_amount'],
                'credit_amount' => 0,
                'description' => "Bill {$bill->bill_number}",
            ]);

            // CR: Accounts Payable
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $apAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $validated['total_amount'],
                'description' => "Bill {$bill->bill_number}",
            ]);

            // Link journal entry to bill
            $bill->update(['journal_entry_id' => $journalEntry->id, 'status' => 'received']);

            DB::commit();

            ActivityLogService::log('created', $request->user(), Bill::class, $bill->id, null, $bill->fresh()->toArray(), null, null, $request);
            $bill->load(['supplier', 'expenseAccount']);

            return response()->json($bill, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bill Creation Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create bill.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a bill
     */
    public function update(Request $request, $id): JsonResponse
    {
        $bill = Bill::findOrFail($id);
        $oldValues = $bill->toArray();

        // Only allow updating if no payments have been made
        if ($bill->paid_amount > 0) {
            return response()->json([
                'message' => 'Cannot update bill with payments. Please void and create a new one.'
            ], 422);
        }

        $accountId = $request->attributes->get('current_account_id');
        $validated = $request->validate([
            'bill_date' => 'required|date',
            'due_date' => 'nullable|date',
            'expense_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
            'total_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $footprint = ActivityLogService::getUserTypeAndId($request->user());
            // Update bill
            $bill->update([
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'] ?? null,
                'expense_account_id' => $validated['expense_account_id'],
                'total_amount' => $validated['total_amount'],
                'balance' => $validated['total_amount'],
                'description' => $validated['description'] ?? null,
                'updated_by_type' => $footprint['user_type'],
                'updated_by_id' => $footprint['user_id'],
            ]);

            // Update journal entry if exists
            if ($bill->journal_entry_id) {
                $journalEntry = JournalEntry::find($bill->journal_entry_id);
                if ($journalEntry) {
                    // Delete old lines
                    $journalEntry->lines()->delete();

                    // Get AP account
                    $apAccount = \App\Models\ChartOfAccount::where('account_code', '2010')->first();

                    // Create new lines
                    JournalEntryLine::create([
                        'journal_entry_id' => $journalEntry->id,
                        'account_id' => $validated['expense_account_id'],
                        'debit_amount' => $validated['total_amount'],
                        'credit_amount' => 0,
                        'description' => "Bill {$bill->bill_number}",
                    ]);

                    JournalEntryLine::create([
                        'journal_entry_id' => $journalEntry->id,
                        'account_id' => $apAccount->id,
                        'debit_amount' => 0,
                        'credit_amount' => $validated['total_amount'],
                        'description' => "Bill {$bill->bill_number}",
                    ]);

                    $journalEntry->update([
                        'total_debit' => $validated['total_amount'],
                        'total_credit' => $validated['total_amount'],
                    ]);
                }
            }

            DB::commit();

            ActivityLogService::log('updated', $request->user(), Bill::class, $bill->id, $oldValues, $bill->fresh()->toArray(), null, null, $request);
            $bill->load(['supplier', 'expenseAccount']);

            return response()->json($bill);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bill Update Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update bill.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a bill. Personnel must provide a valid authorization_code for delete_bill.
     * Allow delete when all payments are voided (effective paid = 0), even if paid_amount is stale.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $bill = Bill::with('payments')->findOrFail($id);
        $user = $request->user();
        $authCodeId = null;

        // Effective paid = sum of non-voided payments only (allows delete when all payments are voided)
        $effectivePaid = (float) $bill->payments()->whereNull('voided_at')->sum('amount');
        if ($effectivePaid > 0) {
            return response()->json([
                'message' => 'Cannot delete bill with payments. Please void all payments first, then try again.'
            ], 422);
        }

        // Sync bill totals in case they were out of sync (e.g. after voiding)
        $totalAmount = (float) $bill->total_amount;
        $bill->paid_amount = $effectivePaid;
        $bill->balance = $totalAmount - $effectivePaid;
        $bill->status = $effectivePaid == 0 ? 'received' : ($bill->balance == 0 ? 'paid' : 'partial');
        $bill->save();

        // Admin does not need authorization code; personnel must provide it
        if (!$user instanceof Admin) {
            $code = $request->input('authorization_code');
            if (!$code) {
                throw ValidationException::withMessages(['authorization_code' => ['This action requires an authorization code from your admin.']]);
            }
            $codeModel = AuthorizationCodeService::validateAndUse($code, 'delete_bill', $user, Bill::class, $bill->id);
            $authCodeId = $codeModel->id;
        }

        try {
            DB::beginTransaction();
            $oldValues = $bill->toArray();

            // Remove related payments first (voided or not) so FK does not block bill delete
            $bill->payments()->delete();

            // Delete journal entry if exists
            if ($bill->journal_entry_id) {
                $bill->journalEntry()->delete();
            }

            $bill->delete();

            DB::commit();

            ActivityLogService::log('deleted', $user, Bill::class, (int) $id, $oldValues, null, $authCodeId, $request->input('remarks'), $request);
            return response()->json(['message' => 'Bill deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bill Deletion Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete bill.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
