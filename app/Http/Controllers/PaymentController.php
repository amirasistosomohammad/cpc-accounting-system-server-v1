<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Invoice;
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

class PaymentController extends Controller
{
    /**
     * Get all payments
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['invoice.client', 'bill.supplier', 'cashAccount']);

        // Filter by type
        if ($request->has('payment_type')) {
            $query->where('payment_type', $request->payment_type);
        }

        // Filter by cash account
        if ($request->has('cash_account_id')) {
            $query->where('cash_account_id', $request->cash_account_id);
        }

        // Filter by client (through invoice relationship for receipts)
        if ($request->has('client_id')) {
            $clientId = $request->client_id;
            $query->whereHas('invoice', function ($q) use ($clientId) {
                $q->where('client_id', $clientId);
            });
        }

        // Filter by supplier (through bill relationship for payments)
        if ($request->has('supplier_id')) {
            $supplierId = $request->supplier_id;
            $query->whereHas('bill', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            });
        }

        // Date range filter
        if ($request->has('start_date')) {
            $query->where('payment_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('payment_date', '<=', $request->end_date);
        }

        $payments = $query->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($payments);
    }

    /**
     * Get a single payment
     */
    public function show($id): JsonResponse
    {
        $payment = Payment::with(['invoice.client', 'bill.supplier', 'cashAccount'])->findOrFail($id);
        $payment->created_by_name = ActivityLogService::resolveNameFromTypeId($payment->created_by_type, $payment->created_by_id);

        return response()->json($payment);
    }

    /**
     * Create a new payment (receipt from client or payment to supplier)
     */
    public function store(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        $validated = $request->validate([
            'payment_type' => 'required|in:receipt,payment',
            'invoice_id' => ['required_if:payment_type,receipt', 'nullable', Rule::exists('invoices', 'id')->where('account_id', $accountId)],
            'bill_id' => ['required_if:payment_type,payment', 'nullable', Rule::exists('bills', 'id')->where('account_id', $accountId)],
            'payment_date' => 'required|date',
            'cash_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|in:cash,check,bank_transfer',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            if ($validated['payment_type'] === 'receipt') {
                // Receipt from client
                $invoice = Invoice::findOrFail($validated['invoice_id']);

                // Check if amount exceeds balance
                if ($validated['amount'] > $invoice->balance) {
                    throw new \Exception('Payment amount cannot exceed invoice balance');
                }

                // Get AR account (1040)
                $arAccount = \App\Models\ChartOfAccount::where('account_code', '1040')->first();
                if (!$arAccount) {
                    throw new \Exception('Accounts Receivable account (1040) not found');
                }

                // Create payment
                $payment = Payment::create([
                    'payment_number' => Payment::generatePaymentNumber('receipt'),
                    'payment_type' => 'receipt',
                    'invoice_id' => $validated['invoice_id'],
                    'payment_date' => $validated['payment_date'],
                    'cash_account_id' => $validated['cash_account_id'],
                    'amount' => $validated['amount'],
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'reference_number' => $validated['reference_number'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                // Update invoice
                $invoice->paid_amount += $validated['amount'];
                $invoice->balance -= $validated['amount'];
                $invoice->status = $invoice->balance == 0 ? 'paid' : ($invoice->paid_amount > 0 ? 'partial' : 'sent');
                $invoice->save();

                // Create journal entry
                $journalEntry = JournalEntry::create([
                    'entry_number' => JournalEntry::generateEntryNumber(),
                    'entry_date' => $validated['payment_date'],
                    'description' => "Payment received for Invoice {$invoice->invoice_number}",
                    'reference_number' => $payment->payment_number,
                    'total_debit' => $validated['amount'],
                    'total_credit' => $validated['amount'],
                    'created_by' => $request->user()->id ?? null,
                ]);

                // DR: Cash Account
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $validated['cash_account_id'],
                    'debit_amount' => $validated['amount'],
                    'credit_amount' => 0,
                    'description' => "Payment for Invoice {$invoice->invoice_number}",
                ]);

                // CR: Accounts Receivable
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $arAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $validated['amount'],
                    'description' => "Payment for Invoice {$invoice->invoice_number}",
                ]);

                $payment->update(['journal_entry_id' => $journalEntry->id]);
                ActivityLogService::log('created', $request->user(), 'payment', $payment->id, null, $payment->fresh()->toArray(), null, null, $request);
            } else {
                // Payment to supplier
                $bill = Bill::findOrFail($validated['bill_id']);

                // Check if amount exceeds balance
                if ($validated['amount'] > $bill->balance) {
                    throw new \Exception('Payment amount cannot exceed bill balance');
                }

                // Get AP account (2010)
                $apAccount = \App\Models\ChartOfAccount::where('account_code', '2010')->first();
                if (!$apAccount) {
                    throw new \Exception('Accounts Payable account (2010) not found');
                }

                $actor = ActivityLogService::getUserTypeAndId($request->user());
                // Create payment
                $payment = Payment::create([
                    'payment_number' => Payment::generatePaymentNumber('payment'),
                    'payment_type' => 'payment',
                    'bill_id' => $validated['bill_id'],
                    'payment_date' => $validated['payment_date'],
                    'cash_account_id' => $validated['cash_account_id'],
                    'amount' => $validated['amount'],
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'reference_number' => $validated['reference_number'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by_type' => $actor['user_type'],
                    'created_by_id' => $actor['user_id'],
                ]);

                // Update bill
                $bill->paid_amount += $validated['amount'];
                $bill->balance -= $validated['amount'];
                $bill->status = $bill->balance == 0 ? 'paid' : ($bill->paid_amount > 0 ? 'partial' : 'received');
                $bill->save();

                // Create journal entry
                $journalEntry = JournalEntry::create([
                    'entry_number' => JournalEntry::generateEntryNumber(),
                    'entry_date' => $validated['payment_date'],
                    'description' => "Payment made for Bill {$bill->bill_number}",
                    'reference_number' => $payment->payment_number,
                    'total_debit' => $validated['amount'],
                    'total_credit' => $validated['amount'],
                    'created_by' => $request->user()->id ?? null,
                ]);

                // DR: Accounts Payable
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $apAccount->id,
                    'debit_amount' => $validated['amount'],
                    'credit_amount' => 0,
                    'description' => "Payment for Bill {$bill->bill_number}",
                ]);

                // CR: Cash Account
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $validated['cash_account_id'],
                    'debit_amount' => 0,
                    'credit_amount' => $validated['amount'],
                    'description' => "Payment for Bill {$bill->bill_number}",
                ]);

                $payment->update(['journal_entry_id' => $journalEntry->id]);
                ActivityLogService::log('created', $request->user(), 'payment', $payment->id, null, $payment->fresh()->toArray(), null, null, $request);
            }

            DB::commit();

            $payment->load(['invoice.client', 'bill.supplier', 'cashAccount']);

            return response()->json($payment, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment Creation Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create payment.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a payment (reverse the transaction)
     */
    public function destroy($id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        try {
            DB::beginTransaction();

            // Reverse the invoice/bill update
            if ($payment->payment_type === 'receipt' && $payment->invoice_id) {
                $invoice = Invoice::find($payment->invoice_id);
                if ($invoice) {
                    $invoice->paid_amount -= $payment->amount;
                    $invoice->balance += $payment->amount;
                    $invoice->status = $invoice->balance == $invoice->total_amount ? 'sent' : 'partial';
                    $invoice->save();
                }
            } elseif ($payment->payment_type === 'payment' && $payment->bill_id) {
                $bill = Bill::find($payment->bill_id);
                if ($bill) {
                    $bill->paid_amount -= $payment->amount;
                    $bill->balance += $payment->amount;
                    $bill->status = $bill->balance == $bill->total_amount ? 'received' : 'partial';
                    $bill->save();
                }
            }

            // Delete journal entry if exists
            if ($payment->journal_entry_id) {
                $payment->journalEntry()->delete();
            }

            $payment->delete();

            DB::commit();

            return response()->json(['message' => 'Payment deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment Deletion Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete payment.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Void a payment (reverse it, keep audit trail). Personnel may require authorization code.
     */
    public function void(Request $request, $id): JsonResponse
    {
        $payment = Payment::with(['invoice', 'journalEntry'])->findOrFail($id);
        $user = $request->user();

        if ($payment->voided_at) {
            return response()->json(['message' => 'This payment is already voided.'], 422);
        }

        $authCodeId = null;
        if (!$user instanceof Admin) {
            $code = $request->input('authorization_code');
            if (!$code) {
                throw ValidationException::withMessages(['authorization_code' => ['This action requires an authorization code from your admin.']]);
            }
            $codeModel = AuthorizationCodeService::validateAndUse($code, 'void_payment', $user, Payment::class, $payment->id);
            $authCodeId = $codeModel->id;
        }

        try {
            DB::beginTransaction();

            if ($payment->payment_type === 'receipt' && $payment->invoice_id) {
                $invoice = Invoice::find($payment->invoice_id);
                if ($invoice) {
                    $invoice->paid_amount -= $payment->amount;
                    $invoice->balance += $payment->amount;
                    $invoice->status = $invoice->balance == $invoice->total_amount ? 'sent' : 'partial';
                    $invoice->save();
                }

                $accountId = $request->attributes->get('current_account_id');
                $arAccount = \App\Models\ChartOfAccount::where('account_code', '1040')
                    ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
                    ->first();
                if (!$arAccount) {
                    throw new \Exception('Accounts Receivable account (1040) not found');
                }

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
            } elseif ($payment->payment_type === 'payment' && $payment->bill_id) {
                $bill = Bill::find($payment->bill_id);
                if ($bill) {
                    $bill->paid_amount -= $payment->amount;
                    $bill->balance += $payment->amount;
                    $bill->status = $bill->balance == $bill->total_amount ? 'received' : 'partial';
                    $bill->save();
                }

                $accountId = $request->attributes->get('current_account_id');
                $apAccount = \App\Models\ChartOfAccount::where('account_code', '2010')
                    ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
                    ->first();
                if (!$apAccount) {
                    throw new \Exception('Accounts Payable account (2010) not found');
                }

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
                    'account_id' => $apAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $payment->amount,
                    'description' => 'Reversal: ' . $payment->payment_number,
                ]);
                JournalEntryLine::create([
                    'journal_entry_id' => $reversingEntry->id,
                    'account_id' => $payment->cash_account_id,
                    'debit_amount' => $payment->amount,
                    'credit_amount' => 0,
                    'description' => 'Reversal: ' . $payment->payment_number,
                ]);
            }

            $actor = ActivityLogService::getUserTypeAndId($user);
            $payment->update([
                'voided_at' => now(),
                'voided_by_type' => $actor['user_type'],
                'voided_by_id' => $actor['user_id'],
            ]);

            ActivityLogService::log('voided', $user, 'payment', $payment->id, null, $payment->fresh()->toArray(), $authCodeId, $request->input('remarks'), $request);

            DB::commit();

            $payment->load(['invoice.client', 'bill.supplier', 'cashAccount']);
            return response()->json(['message' => 'Payment voided successfully.', 'payment' => $payment]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment Void Failed: ' . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to void payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
