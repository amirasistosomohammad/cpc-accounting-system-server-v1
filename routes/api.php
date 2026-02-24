<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// CORS check: open from client origin; response must have Access-Control-Allow-Origin (no auth)
Route::get('/cors-test', fn () => response()->json(['cors' => 'ok', 'message' => 'If you see this from the client app, CORS is working.']));

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);
Route::post('/personnel/login', [AuthController::class, 'personnelLogin']);

// Public avatar route
Route::get('/personnel-avatar/{filename}', function ($filename) {
    $path = storage_path('app/public/personnel-avatars/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->name('personnel.avatar');

// Public account logo route (same pattern as DATravelApp image handling: serve via API so URL is always HTTPS and same-origin)
Route::get('/account-logo/{filename}', function ($filename) {
    $filename = basename($filename);
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
        abort(404);
    }
    $path = storage_path('app/public/account-logos/' . $filename);
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->file($path);
})->name('account.logo');

// Protected routes - using custom Sanctum authentication + account context
Route::middleware([
    \App\Http\Middleware\SanctumAuthenticate::class,
    \App\Http\Middleware\SetCurrentAccount::class,
])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/profile', [AuthController::class, 'getProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // Accounts: list and CRUD (list does not require account context)
    Route::get('/accounts', [\App\Http\Controllers\AccountController::class, 'index']);
    Route::post('/accounts', [\App\Http\Controllers\AccountController::class, 'store']);
    Route::get('/accounts/{id}', [\App\Http\Controllers\AccountController::class, 'show']);
    Route::put('/accounts/{id}', [\App\Http\Controllers\AccountController::class, 'update']);
    Route::post('/accounts/{id}/logo', [\App\Http\Controllers\AccountController::class, 'uploadLogo']);
    Route::delete('/accounts/{id}', [\App\Http\Controllers\AccountController::class, 'destroy']);

    // Admin Management
    Route::prefix('admin')->group(function () {
        Route::apiResource('admins', \App\Http\Controllers\AdminController::class);
        Route::apiResource('personnel', \App\Http\Controllers\PersonnelController::class);
    });

    // Account Types (per business account – scoped by X-Account-Id when sent)
    Route::apiResource('account-types', \App\Http\Controllers\AccountTypeController::class);

    // COA list: fast raw query, NO RequireAccount (avoids 504 → CORS). Same auth + SetCurrentAccount.
    Route::get('accounting/chart-of-accounts-list', [\App\Http\Controllers\ChartOfAccountController::class, 'indexList']);

    // Accounting Routes (require X-Account-Id and valid access)
    Route::middleware([\App\Http\Middleware\RequireAccount::class])->prefix('accounting')->group(function () {
        // Dashboard
        Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);

        // Chart of Accounts (full CRUD; list also available via chart-of-accounts-list above for speed)
        Route::apiResource('chart-of-accounts', \App\Http\Controllers\ChartOfAccountController::class);

        // Journal Entries
        Route::apiResource('journal-entries', \App\Http\Controllers\JournalEntryController::class);

        // Reports
        Route::get('reports/trial-balance', [\App\Http\Controllers\ReportController::class, 'trialBalance']);
        Route::get('reports/trial-balance/export/pdf', [\App\Http\Controllers\ReportController::class, 'exportTrialBalancePdf']);
        Route::get('reports/trial-balance/export/excel', [\App\Http\Controllers\ReportController::class, 'exportTrialBalanceExcel']);
        Route::get('reports/income-statement', [\App\Http\Controllers\ReportController::class, 'incomeStatement']);
        Route::get('reports/income-statement/export/pdf', [\App\Http\Controllers\ReportController::class, 'exportIncomeStatementPdf']);
        Route::get('reports/income-statement/export/excel', [\App\Http\Controllers\ReportController::class, 'exportIncomeStatementExcel']);
        Route::get('reports/balance-sheet', [\App\Http\Controllers\ReportController::class, 'balanceSheet']);
        Route::get('reports/balance-sheet/export/pdf', [\App\Http\Controllers\ReportController::class, 'exportBalanceSheetPdf']);
        Route::get('reports/balance-sheet/export/excel', [\App\Http\Controllers\ReportController::class, 'exportBalanceSheetExcel']);

        // Clients & Accounts Receivable
        Route::apiResource('clients', \App\Http\Controllers\ClientController::class);
        Route::post('invoices/{id}/void', [\App\Http\Controllers\InvoiceController::class, 'void']);
        Route::apiResource('invoices', \App\Http\Controllers\InvoiceController::class);

        // Suppliers & Accounts Payable
        Route::apiResource('suppliers', \App\Http\Controllers\SupplierController::class);
        Route::apiResource('bills', \App\Http\Controllers\BillController::class);

        // Payments (Receipts & Payments)
        Route::post('payments/{id}/void', [\App\Http\Controllers\PaymentController::class, 'void']);
        Route::apiResource('payments', \App\Http\Controllers\PaymentController::class);

        // Cash & Bank
        Route::get('cash-bank', [\App\Http\Controllers\CashBankController::class, 'index']);
        Route::get('cash-bank/{id}', [\App\Http\Controllers\CashBankController::class, 'show']);
    });

    // Time Logs (personnel: clock in/out; admin: list + export)
    Route::prefix('personnel')->group(function () {
        Route::get('time-logs/today', [\App\Http\Controllers\TimeLogController::class, 'today']);
        Route::post('time-logs/time-in', [\App\Http\Controllers\TimeLogController::class, 'timeIn']);
        Route::post('time-logs/time-out', [\App\Http\Controllers\TimeLogController::class, 'timeOut']);
    });
    Route::get('time-logs', [\App\Http\Controllers\TimeLogController::class, 'index']);
    Route::get('time-logs/export/excel', [\App\Http\Controllers\TimeLogController::class, 'exportExcel']);
    Route::get('time-logs/export/pdf', [\App\Http\Controllers\TimeLogController::class, 'exportPdf']);

    // Activity Log (admin only)
    Route::get('activity-logs', [\App\Http\Controllers\ActivityLogController::class, 'index']);
    Route::get('activity-logs/export/excel', [\App\Http\Controllers\ActivityLogController::class, 'exportExcel']);
    Route::get('activity-logs/export/pdf', [\App\Http\Controllers\ActivityLogController::class, 'exportPdf']);

    // Login/Logout Report (admin only)
    Route::get('reports/login-logout', [\App\Http\Controllers\ActivityLogController::class, 'loginLogoutReport']);
    Route::get('reports/login-logout/export/excel', [\App\Http\Controllers\ActivityLogController::class, 'exportLoginLogoutExcel']);
    Route::get('reports/login-logout/export/pdf', [\App\Http\Controllers\ActivityLogController::class, 'exportLoginLogoutPdf']);

    // Authorization Codes (admin: CRUD + list; validate for sensitive actions)
    Route::get('authorization-codes', [\App\Http\Controllers\AuthorizationCodeController::class, 'index']);
    Route::post('authorization-codes', [\App\Http\Controllers\AuthorizationCodeController::class, 'store']);
    Route::put('authorization-codes/{id}', [\App\Http\Controllers\AuthorizationCodeController::class, 'update']);
    Route::delete('authorization-codes/{id}', [\App\Http\Controllers\AuthorizationCodeController::class, 'destroy']);
    Route::post('authorization-codes/validate', [\App\Http\Controllers\AuthorizationCodeController::class, 'validateCode']);
    // Simple flag for personnel: should we require an authorization code?
    Route::get('authorization-codes/has-active', [\App\Http\Controllers\AuthorizationCodeController::class, 'hasActiveCodes']);

    // Test route to verify routing works
    Route::get('/test-route', function () {
        return response()->json(['message' => 'Route working']);
    });

    // Test verification route (for Phase 1 & 2 testing)
    Route::get('/test-verification', function () {
        $results = [];

        // Check Chart of Accounts
        $coaCount = \App\Models\ChartOfAccount::count();
        $results['chart_of_accounts'] = [
            'total_accounts' => $coaCount,
            'status' => $coaCount >= 60 ? 'OK' : 'WARNING: Expected at least 60 accounts'
        ];

        // Check Journal Entries
        $jeCount = \App\Models\JournalEntry::count();
        $results['journal_entries'] = [
            'total_entries' => $jeCount,
            'balanced_entries' => \App\Models\JournalEntry::whereRaw('total_debit = total_credit')->count(),
            'status' => $jeCount > 0 ? 'OK' : 'INFO: No journal entries yet'
        ];

        // Check Clients
        $clientCount = \App\Models\Client::count();
        $results['clients'] = [
            'total_clients' => $clientCount,
            'status' => 'OK'
        ];

        // Check Suppliers
        $supplierCount = \App\Models\Supplier::count();
        $results['suppliers'] = [
            'total_suppliers' => $supplierCount,
            'status' => 'OK'
        ];

        // Check Invoices
        $invoiceCount = \App\Models\Invoice::count();
        $results['invoices'] = [
            'total_invoices' => $invoiceCount,
            'with_journal_entries' => \App\Models\Invoice::whereNotNull('journal_entry_id')->count(),
            'status' => $invoiceCount > 0 ? 'OK' : 'INFO: No invoices yet'
        ];

        // Check Bills
        $billCount = \App\Models\Bill::count();
        $results['bills'] = [
            'total_bills' => $billCount,
            'with_journal_entries' => \App\Models\Bill::whereNotNull('journal_entry_id')->count(),
            'status' => $billCount > 0 ? 'OK' : 'INFO: No bills yet'
        ];

        // Check Payments
        $paymentCount = \App\Models\Payment::count();
        $results['payments'] = [
            'total_payments' => $paymentCount,
            'receipts' => \App\Models\Payment::where('payment_type', 'receipt')->count(),
            'payments' => \App\Models\Payment::where('payment_type', 'payment')->count(),
            'with_journal_entries' => \App\Models\Payment::whereNotNull('journal_entry_id')->count(),
            'status' => $paymentCount > 0 ? 'OK' : 'INFO: No payments yet'
        ];

        // Check Key Account Balances
        $cashOnHand = \App\Models\ChartOfAccount::where('account_code', '1010')->first();
        $cashInBank = \App\Models\ChartOfAccount::where('account_code', '1020')->first();
        $ar = \App\Models\ChartOfAccount::where('account_code', '1040')->first();
        $ap = \App\Models\ChartOfAccount::where('account_code', '2010')->first();

        $results['key_account_balances'] = [
            '1010_cash_on_hand' => $cashOnHand ? number_format($cashOnHand->balance, 2) : 'N/A',
            '1020_cash_in_bank' => $cashInBank ? number_format($cashInBank->balance, 2) : 'N/A',
            '1040_accounts_receivable' => $ar ? number_format($ar->balance, 2) : 'N/A',
            '2010_accounts_payable' => $ap ? number_format($ap->balance, 2) : 'N/A',
        ];

        // Verify Integration
        $results['integration_check'] = [
            'invoices_with_journal_entries' => \App\Models\Invoice::whereNotNull('journal_entry_id')->count() . ' / ' . $invoiceCount,
            'bills_with_journal_entries' => \App\Models\Bill::whereNotNull('journal_entry_id')->count() . ' / ' . $billCount,
            'payments_with_journal_entries' => \App\Models\Payment::whereNotNull('journal_entry_id')->count() . ' / ' . $paymentCount,
            'status' => 'OK'
        ];

        return response()->json([
            'system_status' => 'OK',
            'verification_date' => now()->toDateTimeString(),
            'results' => $results,
            'summary' => [
                'total_journal_entries' => $jeCount,
                'total_clients' => $clientCount,
                'total_suppliers' => $supplierCount,
                'total_invoices' => $invoiceCount,
                'total_bills' => $billCount,
                'total_payments' => $paymentCount,
            ]
        ]);
    });
});
