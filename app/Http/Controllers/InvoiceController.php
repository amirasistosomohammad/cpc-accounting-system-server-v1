<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Services\ActivityLogService;
use App\Services\AuthorizationCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    /**
     * Get all invoices
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['client', 'incomeAccount']);

        // Filter by client
        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Date range filter
        if ($request->has('start_date')) {
            $query->where('invoice_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('invoice_date', '<=', $request->end_date);
        }

        $invoices = $query->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 15));

        // Append footprint for view modal (no extra fetch needed)
        $invoices->getCollection()->transform(function ($invoice) {
            $invoice->created_by_name = ActivityLogService::resolveNameFromTypeId($invoice->created_by_type, $invoice->created_by_id);
            $invoice->updated_by_name = ActivityLogService::resolveNameFromTypeId($invoice->updated_by_type, $invoice->updated_by_id);
            return $invoice;
        });

        return response()->json($invoices);
    }

    /**
     * Get a single invoice
     */
    public function show($id): JsonResponse
    {
        $invoice = Invoice::with(['client', 'incomeAccount', 'payments.cashAccount'])->findOrFail($id);
        $invoice->created_by_name = ActivityLogService::resolveNameFromTypeId($invoice->created_by_type, $invoice->created_by_id);
        $invoice->updated_by_name = ActivityLogService::resolveNameFromTypeId($invoice->updated_by_type, $invoice->updated_by_id);

        return response()->json($invoice);
    }

    /**
     * Create a new invoice
     */
    public function store(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        $validated = $request->validate([
            'client_id' => ['required', Rule::exists('clients', 'id')->where('account_id', $accountId)],
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'income_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
            'total_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Get AR account (1040)
            $arAccount = \App\Models\ChartOfAccount::where('account_code', '1040')->first();
            if (!$arAccount) {
                throw new \Exception('Accounts Receivable account (1040) not found in Chart of Accounts');
            }

            $actor = ActivityLogService::getUserTypeAndId($request->user());
            // Create invoice
            $invoice = Invoice::create([
                'invoice_number' => Invoice::generateInvoiceNumber(),
                'client_id' => $validated['client_id'],
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'income_account_id' => $validated['income_account_id'],
                'total_amount' => $validated['total_amount'],
                'paid_amount' => 0,
                'balance' => $validated['total_amount'],
                'description' => $validated['description'] ?? null,
                'status' => 'draft',
                'created_by_type' => $actor['user_type'],
                'created_by_id' => $actor['user_id'],
            ]);

            // Create journal entry
            $journalEntry = JournalEntry::create([
                'entry_number' => JournalEntry::generateEntryNumber(),
                'entry_date' => $validated['invoice_date'],
                'description' => "Invoice {$invoice->invoice_number} - " . ($validated['description'] ?? 'Invoice created'),
                'reference_number' => $invoice->invoice_number,
                'total_debit' => $validated['total_amount'],
                'total_credit' => $validated['total_amount'],
                'created_by' => $request->user()->id ?? null,
            ]);

            // Create journal entry lines
            // DR: Accounts Receivable
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $arAccount->id,
                'debit_amount' => $validated['total_amount'],
                'credit_amount' => 0,
                'description' => "Invoice {$invoice->invoice_number}",
            ]);

            // CR: Income Account
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $validated['income_account_id'],
                'debit_amount' => 0,
                'credit_amount' => $validated['total_amount'],
                'description' => "Invoice {$invoice->invoice_number}",
            ]);

            // Link journal entry to invoice
            $invoice->update(['journal_entry_id' => $journalEntry->id, 'status' => 'sent']);

            DB::commit();

            ActivityLogService::log('created', $request->user(), 'invoice', $invoice->id, null, $invoice->fresh()->toArray(), null, null, $request);
            $invoice->load(['client', 'incomeAccount']);

            return response()->json($invoice, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice Creation Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create invoice.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an invoice
     */
    public function update(Request $request, $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $oldValues = $invoice->toArray();

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Cannot update a voided invoice.'], 422);
        }

        // Only allow updating if no payments have been made
        if ($invoice->paid_amount > 0) {
            return response()->json([
                'message' => 'This invoice cannot be edited because it has recorded payments. To change details, void all related payments from the Client Ledger (Ledger tab) first, then you can edit this invoice.'
            ], 422);
        }

        $accountId = $request->attributes->get('current_account_id');
        $validated = $request->validate([
            'client_id' => ['required', Rule::exists('clients', 'id')->where('account_id', $accountId)],
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'income_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
            'total_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $actor = ActivityLogService::getUserTypeAndId($request->user());
        $validated['updated_by_type'] = $actor['user_type'];
        $validated['updated_by_id'] = $actor['user_id'];

        try {
            DB::beginTransaction();

            // Update invoice
            $invoice->update([
                'client_id' => $validated['client_id'],
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'income_account_id' => $validated['income_account_id'],
                'total_amount' => $validated['total_amount'],
                'balance' => $validated['total_amount'],
                'description' => $validated['description'] ?? null,
                'updated_by_type' => $actor['user_type'],
                'updated_by_id' => $actor['user_id'],
            ]);

            // Update journal entry if exists
            if ($invoice->journal_entry_id) {
                $journalEntry = JournalEntry::find($invoice->journal_entry_id);
                if ($journalEntry) {
                    // Delete old lines
                    $journalEntry->lines()->delete();

                    // Get AR account
                    $arAccount = \App\Models\ChartOfAccount::where('account_code', '1040')->first();

                    // Create new lines
                    JournalEntryLine::create([
                        'journal_entry_id' => $journalEntry->id,
                        'account_id' => $arAccount->id,
                        'debit_amount' => $validated['total_amount'],
                        'credit_amount' => 0,
                        'description' => "Invoice {$invoice->invoice_number}",
                    ]);

                    JournalEntryLine::create([
                        'journal_entry_id' => $journalEntry->id,
                        'account_id' => $validated['income_account_id'],
                        'debit_amount' => 0,
                        'credit_amount' => $validated['total_amount'],
                        'description' => "Invoice {$invoice->invoice_number}",
                    ]);

                    $journalEntry->update([
                        'total_debit' => $validated['total_amount'],
                        'total_credit' => $validated['total_amount'],
                    ]);
                }
            }

            DB::commit();

            ActivityLogService::log('updated', $request->user(), 'invoice', $invoice->id, $oldValues, $invoice->fresh()->toArray(), null, null, $request);
            $invoice->load(['client', 'incomeAccount']);

            return response()->json($invoice);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice Update Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update invoice.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Void an invoice: reverse all its payments (soft void) and the invoice's own journal entry, then mark invoice as void.
     */
    public function void(Request $request, $id): JsonResponse
    {
        $invoice = Invoice::with(['payments' => fn ($q) => $q->whereNull('voided_at')])->findOrFail($id);
        $user = $request->user();

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'This invoice is already voided.'], 422);
        }

        $authCodeId = null;
        if (!$user instanceof Admin) {
            $code = $request->input('authorization_code');
            if (!$code) {
                throw ValidationException::withMessages(['authorization_code' => ['This action requires an authorization code from your admin.']]);
            }
            $codeModel = AuthorizationCodeService::validateAndUse($code, 'void_invoice', $user, Invoice::class, $invoice->id);
            $authCodeId = $codeModel->id;
        }

        $accountId = $request->attributes->get('current_account_id') ?? $invoice->account_id;
        $arAccount = ChartOfAccount::where('account_code', '1040')
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->first();
        if (!$arAccount) {
            return response()->json(['message' => 'Accounts Receivable account (1040) not found.'], 500);
        }

        try {
            DB::beginTransaction();
            $oldValues = $invoice->toArray();

            // 1. Reverse each non-voided payment (same logic as PaymentController::void for receipts)
            foreach ($invoice->payments as $payment) {
                $invoice->paid_amount -= $payment->amount;
                $invoice->balance += $payment->amount;
                $invoice->status = $invoice->balance == $invoice->total_amount ? 'sent' : 'partial';
                $invoice->save();

                $reversingEntry = JournalEntry::create([
                    'account_id' => $accountId,
                    'entry_number' => JournalEntry::generateEntryNumber(),
                    'entry_date' => now()->toDateString(),
                    'description' => 'Reversal of payment ' . $payment->payment_number,
                    'reference_number' => $payment->payment_number . '-VOID',
                    'total_debit' => $payment->amount,
                    'total_credit' => $payment->amount,
                    'created_by' => $user->id ?? null,
                ]);
                JournalEntryLine::create([
                    'journal_entry_id' => $reversingEntry->id,
                    'account_id' => $arAccount->id,
                    'debit_amount' => $payment->amount,
                    'credit_amount' => 0,
                    'description' => 'Reversal: ' . $payment->payment_number,
                ]);
                JournalEntryLine::create([
                    'journal_entry_id' => $reversingEntry->id,
                    'account_id' => $payment->cash_account_id,
                    'debit_amount' => 0,
                    'credit_amount' => $payment->amount,
                    'description' => 'Reversal: ' . $payment->payment_number,
                ]);

                $actor = ActivityLogService::getUserTypeAndId($user);
                $payment->update([
                    'voided_at' => now(),
                    'voided_by_type' => $actor['user_type'],
                    'voided_by_id' => $actor['user_id'],
                ]);
            }

            // 2. Reverse the invoice's own journal entry (DR Income, CR AR)
            $totalAmount = (float) $invoice->total_amount;
            if ($totalAmount > 0 && $invoice->income_account_id) {
                $reversingEntry = JournalEntry::create([
                    'account_id' => $accountId,
                    'entry_number' => JournalEntry::generateEntryNumber(),
                    'entry_date' => now()->toDateString(),
                    'description' => 'Reversal of invoice ' . $invoice->invoice_number,
                    'reference_number' => $invoice->invoice_number . '-VOID',
                    'total_debit' => $totalAmount,
                    'total_credit' => $totalAmount,
                    'created_by' => $user->id ?? null,
                ]);
                JournalEntryLine::create([
                    'journal_entry_id' => $reversingEntry->id,
                    'account_id' => $invoice->income_account_id,
                    'debit_amount' => $totalAmount,
                    'credit_amount' => 0,
                    'description' => 'Reversal: Invoice ' . $invoice->invoice_number,
                ]);
                JournalEntryLine::create([
                    'journal_entry_id' => $reversingEntry->id,
                    'account_id' => $arAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $totalAmount,
                    'description' => 'Reversal: Invoice ' . $invoice->invoice_number,
                ]);
            }

            // 3. Mark invoice as void
            $invoice->update([
                'status' => 'void',
                'paid_amount' => 0,
                'balance' => 0,
            ]);

            ActivityLogService::log('voided', $user, 'invoice', (int) $id, $oldValues, $invoice->fresh()->toArray(), $authCodeId, $request->input('remarks'), $request);

            DB::commit();

            $invoice->load(['client', 'incomeAccount']);
            return response()->json(['message' => 'Invoice voided successfully.', 'invoice' => $invoice]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice Void Failed: ' . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to void invoice.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an invoice. Personnel must provide a valid authorization_code for delete_invoice.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $user = $request->user();
        $authCodeId = null;

        if (!$user instanceof Admin) {
            $code = $request->input('authorization_code');
            if (!$code) {
                throw ValidationException::withMessages(['authorization_code' => ['This action requires an authorization code from your admin.']]);
            }
            $codeModel = AuthorizationCodeService::validateAndUse($code, 'delete_invoice', $user, Invoice::class, $invoice->id);
            $authCodeId = $codeModel->id;
        }

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'This invoice is voided and cannot be deleted.'], 422);
        }

        if ($invoice->paid_amount > 0) {
            return response()->json([
                'message' => 'This invoice cannot be deleted because it has recorded payments. Please void all related payments from the Client Ledger (Ledger tab) first, then try again.'
            ], 422);
        }

        $invoiceData = $invoice->toArray();

        try {
            DB::beginTransaction();

            // Remove related payments first (e.g. voided payments) so FK does not block invoice delete
            $invoice->payments()->delete();

            if ($invoice->journal_entry_id) {
                $invoice->journalEntry()->delete();
            }

            $invoice->delete();

            DB::commit();

            ActivityLogService::log('deleted', $user, 'invoice', (int) $id, $invoiceData, null, $authCodeId, $request->input('remarks'), $request);
            return response()->json(['message' => 'Invoice deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice Deletion Failed: ' . $e->getMessage());

            $message = 'Failed to delete invoice.';
            // Surface DB/constraint errors in a user-friendly way
            if (str_contains($e->getMessage(), 'foreign key') || str_contains($e->getMessage(), 'Integrity constraint')) {
                $message = 'This invoice cannot be deleted because it is still linked to payment or journal records. Please void all related payments from the Client Ledger first, then try again.';
            } elseif ($e->getMessage()) {
                $message = 'Failed to delete invoice. ' . $e->getMessage();
            }

            return response()->json([
                'message' => $message,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
