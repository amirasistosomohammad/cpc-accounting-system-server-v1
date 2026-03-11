<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\Invoice;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Client;
use App\Models\Supplier;
use App\Models\ChartOfAccount;
use App\Models\AccountType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics and data
     */
    public function index(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        // Set default date range if not provided (current month)
        if (!$startDate || !$endDate) {
            $now = Carbon::now();
            $startDate = $now->copy()->startOfMonth()->toDateString();
            $endDate = $now->toDateString();
        }

        $startDateCarbon = Carbon::parse($startDate);
        $endDateCarbon = Carbon::parse($endDate);

        // Phase 1: Financial Overview
        $overview = $this->getFinancialOverview($startDateCarbon, $endDateCarbon);

        // Phase 2: Recent Transactions
        $recentTransactions = $this->getRecentTransactions();

        // Phase 3: Monthly Data for Charts
        $monthlyData = $this->getMonthlyData($startDateCarbon, $endDateCarbon);

        // Phase 6: Summary Tables
        $topAccounts = $this->getTopAccounts($startDateCarbon, $endDateCarbon);
        $topClients = $this->getTopClients($startDateCarbon, $endDateCarbon);
        $topSuppliers = $this->getTopSuppliers($startDateCarbon, $endDateCarbon);

        // Phase 5: Alerts
        $alerts = $this->getAlerts();

        return response()->json([
            'overview' => $overview,
            'recentTransactions' => $recentTransactions,
            'monthlyData' => $monthlyData,
            'topAccounts' => $topAccounts,
            'topClients' => $topClients,
            'topSuppliers' => $topSuppliers,
            'alerts' => $alerts,
        ]);
    }

    /**
     * Phase 1: Financial Overview
     */
    private function getFinancialOverview($startDate, $endDate): array
    {
        $accountId = request()->attributes->get('current_account_id');
        
        // Total Income (from invoices)
        $totalIncome = Invoice::whereBetween('invoice_date', [$startDate, $endDate])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->sum('total_amount');

        // Total Expenses: Calculate from journal entries with expense category accounts
        // This ensures bills using asset accounts (e.g., Advances to Suppliers) are not counted as expenses
        $expenseAccountIds = ChartOfAccount::whereHas('accountType', function ($q) {
                $q->where('category', AccountType::CATEGORY_EXPENSE);
            })
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->pluck('id');
        
        $totalExpenses = 0;
        if ($expenseAccountIds->isNotEmpty()) {
            $totalExpenses = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->whereIn('journal_entry_lines.account_id', $expenseAccountIds)
                ->whereBetween('journal_entries.entry_date', [$startDate, $endDate])
                ->when($accountId, fn($q) => $q->where('journal_entries.account_id', $accountId))
                ->sum(DB::raw('journal_entry_lines.debit_amount - journal_entry_lines.credit_amount'));
        }

        // Net Income
        $netIncome = $totalIncome - $totalExpenses;

        // Cash Balance (from cash/bank accounts - account codes 1010, 1020, 1030)
        $cashAccounts = ChartOfAccount::whereIn('account_code', ['1010', '1020', '1030'])
            ->where('is_active', true)
            ->with('journalEntryLines')
            ->get();

        $cashBalance = $cashAccounts->sum('balance');

        // Accounts Receivable (unpaid invoices)
        $accountsReceivable = Invoice::where('status', '!=', 'paid')
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->sum('total_amount');

        // Accounts Payable (unpaid bills)
        $accountsPayable = Bill::where('status', '!=', 'paid')
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->sum('total_amount');

        // Total Journal Entries
        $totalJournalEntries = JournalEntry::when($accountId, fn($q) => $q->where('account_id', $accountId))->count();

        return [
            'totalIncome' => (float) $totalIncome,
            'totalExpenses' => (float) $totalExpenses,
            'netIncome' => (float) $netIncome,
            'cashBalance' => (float) $cashBalance,
            'accountsReceivable' => (float) $accountsReceivable,
            'accountsPayable' => (float) $accountsPayable,
            'totalJournalEntries' => $totalJournalEntries,
        ];
    }

    /**
     * Phase 2: Recent Transactions
     */
    private function getRecentTransactions(): array
    {
        $recentJournals = JournalEntry::with('lines.account')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'entry_number' => $entry->entry_number,
                    'entry_date' => $entry->entry_date,
                    'description' => $entry->description,
                    'total_debit' => $entry->total_debit,
                    'total_credit' => $entry->total_credit,
                    'created_at' => $entry->created_at,
                ];
            });

        $recentInvoices = Invoice::with('client', 'incomeAccount')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date,
                    'due_date' => $invoice->due_date,
                    'total_amount' => $invoice->total_amount,
                    'status' => $invoice->status,
                    'client_name' => $invoice->client->name ?? 'N/A',
                    'created_at' => $invoice->created_at,
                ];
            });

        $recentBills = Bill::with('supplier', 'expenseAccount')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($bill) {
                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date,
                    'due_date' => $bill->due_date,
                    'total_amount' => $bill->total_amount,
                    'status' => $bill->status,
                    'supplier_name' => $bill->supplier->name ?? 'N/A',
                    'created_at' => $bill->created_at,
                ];
            });

        return [
            'journals' => $recentJournals,
            'invoices' => $recentInvoices,
            'bills' => $recentBills,
        ];
    }

    /**
     * Phase 3: Monthly Data for Charts
     */
    private function getMonthlyData($startDate, $endDate): array
    {
        $accountId = request()->attributes->get('current_account_id');
        
        // Get invoices grouped by month
        $invoicesByMonth = Invoice::whereBetween('invoice_date', [$startDate, $endDate])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->select(
                DB::raw('DATE_FORMAT(invoice_date, "%Y-%m") as month'),
                DB::raw('SUM(total_amount) as total')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        // Get expenses from journal entries with expense category accounts (not from bills directly)
        // This ensures bills using asset accounts are not counted as expenses
        $expenseAccountIds = ChartOfAccount::whereHas('accountType', function ($q) {
                $q->where('category', AccountType::CATEGORY_EXPENSE);
            })
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->pluck('id');
        
        $expensesByMonth = collect();
        if ($expenseAccountIds->isNotEmpty()) {
            $expensesByMonth = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->whereIn('journal_entry_lines.account_id', $expenseAccountIds)
                ->whereBetween('journal_entries.entry_date', [$startDate, $endDate])
                ->when($accountId, fn($q) => $q->where('journal_entries.account_id', $accountId))
                ->select(
                    DB::raw('DATE_FORMAT(journal_entries.entry_date, "%Y-%m") as month'),
                    DB::raw('SUM(journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) as total')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month');
        }

        // Combine months
        $allMonths = collect($invoicesByMonth->keys())
            ->merge($expensesByMonth->keys())
            ->unique()
            ->sort()
            ->values();

        $monthlyData = [];
        foreach ($allMonths as $month) {
            $monthlyData[] = [
                'month' => $month,
                'income' => (float) ($invoicesByMonth[$month]->total ?? 0),
                'expenses' => (float) ($expensesByMonth[$month]->total ?? 0),
            ];
        }

        return $monthlyData;
    }

    /**
     * Phase 6: Top Accounts
     */
    private function getTopAccounts($startDate, $endDate): array
    {
        $accountId = request()->attributes->get('current_account_id');
        
        // Top Income Accounts
        $topIncomeAccounts = Invoice::whereBetween('invoice_date', [$startDate, $endDate])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->with('incomeAccount')
            ->select('income_account_id', DB::raw('SUM(total_amount) as total'))
            ->groupBy('income_account_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'account_name' => $item->incomeAccount->account_name ?? 'Other',
                    'total' => (float) $item->total,
                ];
            });

        // Top Expense Accounts: Calculate from journal entries with expense category accounts only
        // This ensures bills using asset accounts are not included in expense accounts
        $expenseAccountIds = ChartOfAccount::whereHas('accountType', function ($q) {
                $q->where('category', AccountType::CATEGORY_EXPENSE);
            })
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->pluck('id');
        
        $topExpenseAccounts = collect();
        if ($expenseAccountIds->isNotEmpty()) {
            $topExpenseAccounts = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->join('chart_of_accounts', 'journal_entry_lines.account_id', '=', 'chart_of_accounts.id')
                ->whereIn('journal_entry_lines.account_id', $expenseAccountIds)
                ->whereBetween('journal_entries.entry_date', [$startDate, $endDate])
                ->when($accountId, fn($q) => $q->where('journal_entries.account_id', $accountId))
                ->select(
                    'journal_entry_lines.account_id',
                    'chart_of_accounts.account_name',
                    DB::raw('SUM(journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) as total')
                )
                ->groupBy('journal_entry_lines.account_id', 'chart_of_accounts.account_name')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'account_name' => $item->account_name ?? 'Other',
                        'total' => (float) $item->total,
                    ];
                });
        }

        return [
            'income' => $topIncomeAccounts,
            'expenses' => $topExpenseAccounts,
        ];
    }

    /**
     * Phase 6: Top Clients
     */
    private function getTopClients($startDate, $endDate): array
    {
        return Invoice::whereBetween('invoice_date', [$startDate, $endDate])
            ->with('client')
            ->select('client_id', DB::raw('SUM(total_amount) as total'))
            ->groupBy('client_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'client_name' => $item->client->name ?? 'Other',
                    'total' => (float) $item->total,
                ];
            })
            ->toArray();
    }

    /**
     * Phase 6: Top Suppliers
     */
    private function getTopSuppliers($startDate, $endDate): array
    {
        return Bill::whereBetween('bill_date', [$startDate, $endDate])
            ->with('supplier')
            ->select('supplier_id', DB::raw('SUM(total_amount) as total'))
            ->groupBy('supplier_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'supplier_name' => $item->supplier->name ?? 'Other',
                    'total' => (float) $item->total,
                ];
            })
            ->toArray();
    }

    /**
     * Phase 5: Alerts
     */
    private function getAlerts(): array
    {
        $today = Carbon::today();

        // Overdue Invoices
        $overdueInvoices = Invoice::where('status', '!=', 'paid')
            ->where('due_date', '<', $today)
            ->get();

        $overdueInvoicesList = $overdueInvoices->take(5)->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'due_date' => $invoice->due_date,
                'total_amount' => $invoice->total_amount,
                'client_name' => $invoice->client->name ?? 'N/A',
            ];
        });

        // Overdue Bills
        $overdueBills = Bill::where('status', '!=', 'paid')
            ->where('due_date', '<', $today)
            ->get();

        $overdueBillsList = $overdueBills->take(5)->map(function ($bill) {
            return [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'due_date' => $bill->due_date,
                'total_amount' => $bill->total_amount,
                'supplier_name' => $bill->supplier->name ?? 'N/A',
            ];
        });

        // Low Cash Accounts (balance < 10,000)
        // Note: balance is a computed attribute, so we need to calculate it manually
        $cashAccounts = ChartOfAccount::whereIn('account_code', ['1010', '1020', '1030'])
            ->where('is_active', true)
            ->with('journalEntryLines')
            ->get();
        
        $lowCashAccounts = $cashAccounts->filter(function ($account) {
            $debits = $account->journalEntryLines->sum('debit_amount');
            $credits = $account->journalEntryLines->sum('credit_amount');
            $balance = $account->normal_balance === 'DR' 
                ? ($debits - $credits) 
                : ($credits - $debits);
            return $balance < 10000;
        });

        // Unbalanced Journal Entries
        $unbalancedEntries = JournalEntry::whereRaw('ABS(total_debit - total_credit) > 0.01')
            ->get();

        return [
            'overdueInvoices' => $overdueInvoices->count(),
            'overdueBills' => $overdueBills->count(),
            'lowCashAccounts' => $lowCashAccounts->count(),
            'unbalancedEntries' => $unbalancedEntries->count(),
            'overdueInvoicesList' => $overdueInvoicesList,
            'overdueBillsList' => $overdueBillsList,
        ];
    }
}

