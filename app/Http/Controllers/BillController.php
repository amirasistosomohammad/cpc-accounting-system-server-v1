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
        $query = Bill::with(['supplier', 'expenseAccount', 'convertedExpenseAccount']);

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
        $bill = Bill::with(['supplier', 'expenseAccount.accountType', 'convertedExpenseAccount', 'conversionJournalEntry', 'payments.cashAccount'])->findOrFail($id);
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
            // Note: expense_account_id can be either expense or asset account
            // Asset accounts (e.g., "Advances to Suppliers", "Prepaid Expenses") are used for advance payments
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
            // DR: Account (can be expense or asset, depending on bill type)
            // For regular bills: expense account
            // For advance payments: asset account (e.g., "Advances to Suppliers", "Prepaid Expenses")
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

    /**
     * Convert an asset bill to expense (for advance payments that are now completed)
     * This creates a reversing entry: DR: Expense Account, CR: Asset Account
     */
    public function convertToExpense(Request $request, $id): JsonResponse
    {
        $bill = Bill::with(['expenseAccount.accountType'])->findOrFail($id);
        $user = $request->user();
        $accountId = $request->attributes->get('current_account_id');

        // Validation: Bill must be fully paid
        if ($bill->balance > 0) {
            return response()->json([
                'message' => 'Bill must be fully paid before converting to expense. Current balance: ' . number_format($bill->balance, 2)
            ], 422);
        }

        // Validation: Bill must use an asset account
        $accountCategory = $bill->expenseAccount->account_type_category ?? null;
        if ($accountCategory !== 'asset') {
            return response()->json([
                'message' => 'This bill does not use an asset account. Only asset bills (advance payments) can be converted to expense.'
            ], 422);
        }

        // Validation: Bill must not already be converted
        if ($bill->converted_to_expense_at !== null) {
            return response()->json([
                'message' => 'This bill has already been converted to expense.'
            ], 422);
        }

        // Validate expense account selection
        $validated = $request->validate([
            'expense_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
        ]);

        // Verify the selected account is an expense account
        $expenseAccount = \App\Models\ChartOfAccount::with('accountType')->find($validated['expense_account_id']);
        if (!$expenseAccount) {
            return response()->json([
                'message' => 'Selected expense account not found.'
            ], 422);
        }

        $expenseAccountCategory = $expenseAccount->account_type_category ?? null;
        if ($expenseAccountCategory !== 'expense') {
            return response()->json([
                'message' => 'Selected account must be an expense account.'
            ], 422);
        }

        try {
            DB::beginTransaction();
            $footprint = ActivityLogService::getUserTypeAndId($request->user());
            $oldValues = $bill->toArray();

            // Create reversing journal entry
            // DR: Expense Account (selected by user)
            // CR: Asset Account (original bill account)
            $journalEntry = JournalEntry::create([
                'account_id' => $accountId,
                'entry_number' => JournalEntry::generateEntryNumber(),
                'entry_date' => now()->toDateString(),
                'description' => "Convert Bill {$bill->bill_number} from Asset to Expense - " . ($bill->description ?? 'Advance payment completed'),
                'reference_number' => $bill->bill_number . '-CONV',
                'total_debit' => $bill->total_amount,
                'total_credit' => $bill->total_amount,
                'created_by' => $request->user()->id ?? null,
                'created_by_type' => $footprint['user_type'],
            ]);

            // DR: Expense Account
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $validated['expense_account_id'],
                'debit_amount' => $bill->total_amount,
                'credit_amount' => 0,
                'description' => "Convert Bill {$bill->bill_number} to expense",
            ]);

            // CR: Asset Account (original bill account)
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $bill->expense_account_id,
                'debit_amount' => 0,
                'credit_amount' => $bill->total_amount,
                'description' => "Convert Bill {$bill->bill_number} from asset",
            ]);

            // Update bill with conversion details
            $bill->update([
                'converted_to_expense_at' => now(),
                'converted_expense_account_id' => $validated['expense_account_id'],
                'conversion_journal_entry_id' => $journalEntry->id,
                'updated_by_type' => $footprint['user_type'],
                'updated_by_id' => $footprint['user_id'],
            ]);

            DB::commit();

            ActivityLogService::log('converted_to_expense', $user, Bill::class, $bill->id, $oldValues, $bill->fresh()->toArray(), null, $request->input('remarks'), $request);
            $bill->load(['supplier', 'expenseAccount', 'convertedExpenseAccount']);

            return response()->json([
                'message' => 'Bill converted to expense successfully.',
                'bill' => $bill
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bill Conversion Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to convert bill to expense.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Edit conversion: Change the expense account for an already converted bill
     * Creates reversing entries and a new conversion entry
     */
    public function editConversion(Request $request, $id): JsonResponse
    {
        $bill = Bill::with(['expenseAccount.accountType', 'convertedExpenseAccount', 'conversionJournalEntry'])->findOrFail($id);
        $user = $request->user();
        $accountId = $request->attributes->get('current_account_id');

        // Validation: Bill must be fully paid
        if ($bill->balance > 0) {
            return response()->json([
                'message' => 'Bill must be fully paid before editing conversion. Current balance: ' . number_format($bill->balance, 2)
            ], 422);
        }

        // Validation: Bill must be already converted
        if ($bill->converted_to_expense_at === null) {
            return response()->json([
                'message' => 'This bill has not been converted to expense yet.'
            ], 422);
        }

        // Validation: Bill must use an asset account
        $accountCategory = $bill->expenseAccount->account_type_category ?? null;
        if ($accountCategory !== 'asset') {
            return response()->json([
                'message' => 'This bill does not use an asset account. Only asset bills (advance payments) can have conversions edited.'
            ], 422);
        }

        // Validate expense account selection
        $validated = $request->validate([
            'expense_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
        ]);

        // Verify the selected account is an expense account
        $expenseAccount = \App\Models\ChartOfAccount::with('accountType')->find($validated['expense_account_id']);
        if (!$expenseAccount) {
            return response()->json([
                'message' => 'Selected expense account not found.'
            ], 422);
        }

        $expenseAccountCategory = $expenseAccount->account_type_category ?? null;
        if ($expenseAccountCategory !== 'expense') {
            return response()->json([
                'message' => 'Selected account must be an expense account.'
            ], 422);
        }

        // Check if the new expense account is different from the current one
        if ($bill->converted_expense_account_id == $validated['expense_account_id']) {
            return response()->json([
                'message' => 'The selected expense account is the same as the current one.'
            ], 422);
        }

        try {
            DB::beginTransaction();
            $footprint = ActivityLogService::getUserTypeAndId($request->user());
            $oldValues = $bill->toArray();

            $oldExpenseAccount = $bill->convertedExpenseAccount;
            $oldJournalEntryId = $bill->conversion_journal_entry_id;

            // Step 1: Reverse the old conversion entry
            // DR: Old Expense Account (credit it back)
            // CR: Asset Account (debit it back)
            $reversingEntry = JournalEntry::create([
                'account_id' => $accountId,
                'entry_number' => JournalEntry::generateEntryNumber(),
                'entry_date' => now()->toDateString(),
                'description' => "Reverse conversion for Bill {$bill->bill_number} - Changing expense account",
                'reference_number' => $bill->bill_number . '-CONV-REV',
                'total_debit' => $bill->total_amount,
                'total_credit' => $bill->total_amount,
                'created_by' => $request->user()->id ?? null,
                'created_by_type' => $footprint['user_type'],
            ]);

            // DR: Old Expense Account (reverse the debit)
            JournalEntryLine::create([
                'journal_entry_id' => $reversingEntry->id,
                'account_id' => $bill->converted_expense_account_id,
                'debit_amount' => 0,
                'credit_amount' => $bill->total_amount,
                'description' => "Reverse conversion from {$oldExpenseAccount->account_code}",
            ]);

            // CR: Asset Account (reverse the credit)
            JournalEntryLine::create([
                'journal_entry_id' => $reversingEntry->id,
                'account_id' => $bill->expense_account_id,
                'debit_amount' => $bill->total_amount,
                'credit_amount' => 0,
                'description' => "Reverse conversion for Bill {$bill->bill_number}",
            ]);

            // Step 2: Create new conversion entry with new expense account
            // DR: New Expense Account
            // CR: Asset Account
            $newJournalEntry = JournalEntry::create([
                'account_id' => $accountId,
                'entry_number' => JournalEntry::generateEntryNumber(),
                'entry_date' => now()->toDateString(),
                'description' => "Convert Bill {$bill->bill_number} from Asset to Expense (Updated) - " . ($bill->description ?? 'Advance payment completed'),
                'reference_number' => $bill->bill_number . '-CONV',
                'total_debit' => $bill->total_amount,
                'total_credit' => $bill->total_amount,
                'created_by' => $request->user()->id ?? null,
                'created_by_type' => $footprint['user_type'],
            ]);

            // DR: New Expense Account
            JournalEntryLine::create([
                'journal_entry_id' => $newJournalEntry->id,
                'account_id' => $validated['expense_account_id'],
                'debit_amount' => $bill->total_amount,
                'credit_amount' => 0,
                'description' => "Convert Bill {$bill->bill_number} to expense (updated)",
            ]);

            // CR: Asset Account
            JournalEntryLine::create([
                'journal_entry_id' => $newJournalEntry->id,
                'account_id' => $bill->expense_account_id,
                'debit_amount' => 0,
                'credit_amount' => $bill->total_amount,
                'description' => "Convert Bill {$bill->bill_number} from asset",
            ]);

            // Step 3: Update bill with new conversion details
            $bill->update([
                'converted_to_expense_at' => now(), // Update timestamp to reflect the edit
                'converted_expense_account_id' => $validated['expense_account_id'],
                'conversion_journal_entry_id' => $newJournalEntry->id,
                'updated_by_type' => $footprint['user_type'],
                'updated_by_id' => $footprint['user_id'],
            ]);

            DB::commit();

            ActivityLogService::log('edited_conversion', $user, Bill::class, $bill->id, $oldValues, $bill->fresh()->toArray(), null, $request->input('remarks'), $request);
            $bill->load(['supplier', 'expenseAccount', 'convertedExpenseAccount', 'conversionJournalEntry']);

            return response()->json([
                'message' => 'Conversion updated successfully. Old conversion reversed and new conversion created.',
                'bill' => $bill
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Edit Conversion Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to edit conversion.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
