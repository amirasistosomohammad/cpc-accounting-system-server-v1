<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\AccountType;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Trial Balance — one bulk balance query to avoid 504 (gateway timeout → CORS error).
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        if ($accountId === null || $accountId === '') {
            return response()->json([
                'message' => 'Account context required. Please select a business account.',
            ], 422);
        }

        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $accounts = ChartOfAccount::with('accountType')
            ->where('account_id', $accountId)
            ->orderBy('account_code')
            ->get();
        $accountIds = $accounts->pluck('id')->all();
        $balancesById = $this->balancesByAccountIdAndDateRange($accountIds, $startDate, $endDate, $accountId);

        $data = $accounts->map(function ($account) use ($balancesById) {
            [$debits, $credits] = $balancesById[$account->id] ?? [0, 0];
            // For Trial Balance, we want net DR/CR based purely on totals:
            // if debits > credits → net debit; if credits > debits → net credit.
            $debitBalance = 0;
            $creditBalance = 0;
            if ($debits > $credits) {
                $debitBalance = $debits - $credits;
            } elseif ($credits > $debits) {
                $creditBalance = $credits - $debits;
            }
            // Keep a signed balance for other uses (DR positive, CR negative),
            // but DO NOT use this to decide which column to show in the TB.
            $balance = $debitBalance - $creditBalance;
            return [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'normal_balance' => $account->normal_balance,
                'debits' => round($debits, 2),
                'credits' => round($credits, 2),
                'balance' => round($balance, 2),
                'debit_balance' => round($debitBalance, 2),
                'credit_balance' => round($creditBalance, 2),
            ];
        });

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'accounts' => $data,
        ]);
    }

    /**
     * Income Statement — dynamic by account type category (revenue, expense).
     * Includes all COAs whose account type has category in revenue/expense.
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        if ($accountId === null || $accountId === '') {
            return response()->json([
                'message' => 'Account context required. Please select a business account.',
            ], 422);
        }

        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $categories = [AccountType::CATEGORY_REVENUE, AccountType::CATEGORY_EXPENSE];
        $sectionLabels = [
            AccountType::CATEGORY_REVENUE => 'Revenue',
            AccountType::CATEGORY_EXPENSE => 'Expenses',
        ];
        $sections = [];
        foreach ($categories as $cat) {
            $sections[$cat] = ['label' => $sectionLabels[$cat], 'total' => 0];
        }

        $accounts = ChartOfAccount::with('accountType')
            ->where('account_id', $accountId)
            ->whereHas('accountType', function ($q) use ($categories) {
                $q->whereIn('category', $categories);
            })
            ->orderBy('account_code')
            ->get();

        $accountIds = $accounts->pluck('id')->all();
        $balancesById = $this->balancesByAccountIdAndDateRange($accountIds, $startDate, $endDate, $accountId);
        $lines = [];

        foreach ($accounts as $account) {
            $category = $account->account_type_category;
            if ($category === null || !isset($sections[$category])) {
                continue;
            }
            [$debits, $credits] = $balancesById[$account->id] ?? [0, 0];
            $amount = $account->normal_balance === 'CR' ? ($credits - $debits) : ($debits - $credits);
            $lines[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_type' => $category,
                'amount' => round($amount, 2),
            ];
            $sections[$category]['total'] += $amount;
        }

        $totalRevenue = $sections[AccountType::CATEGORY_REVENUE]['total'] ?? 0;
        $totalExpense = $sections[AccountType::CATEGORY_EXPENSE]['total'] ?? 0;
        $netIncome = $totalRevenue - $totalExpense;

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'sections' => $sections,
            'lines' => $lines,
            'totals' => [
                'gross_profit' => round($totalRevenue - $totalExpense, 2),
                'operating_income' => round($totalRevenue - $totalExpense, 2),
                'net_income' => round($netIncome, 2),
            ],
        ]);
    }

    /**
     * Balance Sheet — dynamic by account type category. One bulk query for balances to avoid 504.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        if ($accountId === null || $accountId === '') {
            return response()->json([
                'message' => 'Account context required. Please select a business account.',
            ], 422);
        }

        $endDate = $request->get('end_date');
        $startDate = $request->get('start_date');

        $categories = [AccountType::CATEGORY_ASSET, AccountType::CATEGORY_LIABILITY, AccountType::CATEGORY_EQUITY];
        $sectionLabels = [
            AccountType::CATEGORY_ASSET => 'Assets',
            AccountType::CATEGORY_LIABILITY => 'Liabilities',
            AccountType::CATEGORY_EQUITY => 'Equity',
        ];
        $sections = [];
        foreach ($categories as $cat) {
            $sections[$cat] = ['label' => $sectionLabels[$cat], 'total' => 0];
        }

        $accounts = ChartOfAccount::with('accountType')
            ->where('account_id', $accountId)
            ->whereHas('accountType', function ($q) use ($categories) {
                $q->whereIn('category', $categories);
            })
            ->orderBy('account_code')
            ->get();

        $accountIds = $accounts->pluck('id')->all();
        $balancesById = $this->balancesByAccountIdAndDateRange($accountIds, $startDate, $endDate, $accountId);

        $lines = [];
        foreach ($accounts as $account) {
            $category = $account->account_type_category;
            if ($category === null || !isset($sections[$category])) {
                continue;
            }
            [$debits, $credits] = $balancesById[$account->id] ?? [0, 0];
            $balance = $account->normal_balance === 'DR' ? $debits - $credits : $credits - $debits;
            $lines[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_type' => $category,
                'balance' => round($balance, 2),
            ];
            $sections[$category]['total'] += $balance;
        }

        $pnl = $this->computeNetIncome($startDate, $endDate, $accountId);
        $sections[AccountType::CATEGORY_EQUITY]['total'] += $pnl;

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'sections' => $sections,
            'lines' => $lines,
            'totals' => [
                'assets' => round($sections[AccountType::CATEGORY_ASSET]['total'], 2),
                'liabilities' => round($sections[AccountType::CATEGORY_LIABILITY]['total'], 2),
                'equity' => round($sections[AccountType::CATEGORY_EQUITY]['total'], 2),
                'liabilities_equity' => round($sections[AccountType::CATEGORY_LIABILITY]['total'] + $sections[AccountType::CATEGORY_EQUITY]['total'], 2),
                'net_income' => round($pnl, 2),
            ],
        ]);
    }

    /**
     * One query: account_id => [sum(debits), sum(credits)] for given account IDs and optional date range, scoped to a business account.
     */
    private function balancesByAccountIdAndDateRange(array $accountIds, $startDate, $endDate, $accountId = null): array
    {
        if (empty($accountIds)) {
            return [];
        }
        $q = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->selectRaw('journal_entry_lines.account_id, COALESCE(SUM(journal_entry_lines.debit_amount),0) as debits, COALESCE(SUM(journal_entry_lines.credit_amount),0) as credits')
            ->groupBy('journal_entry_lines.account_id');
        if ($accountId !== null && $accountId !== '') {
            $q->where('journal_entries.account_id', $accountId);
        }
        if ($startDate) {
            $q->where('journal_entries.entry_date', '>=', $startDate);
        }
        if ($endDate) {
            $q->where('journal_entries.entry_date', '<=', $endDate);
        }
        $out = [];
        foreach ($q->get() as $row) {
            $out[(int) $row->account_id] = [(float) $row->debits, (float) $row->credits];
        }
        return $out;
    }

    /**
     * Helper: Compute Net Income for a date range, scoped to a business account. One bulk query for balances.
     */
    private function computeNetIncome($startDate, $endDate, $accountId): float
    {
        $accounts = ChartOfAccount::with('accountType')
            ->where('account_id', $accountId)
            ->whereHas('accountType', function ($q) {
                $q->whereIn('category', [
                    AccountType::CATEGORY_REVENUE,
                    AccountType::CATEGORY_EXPENSE,
                ]);
            })
            ->get();

        $accountIds = $accounts->pluck('id')->all();
        $balancesById = $this->balancesByAccountIdAndDateRange($accountIds, $startDate, $endDate, $accountId);

        $net = 0;
        foreach ($accounts as $account) {
            [$debits, $credits] = $balancesById[$account->id] ?? [0, 0];
            $amount = $account->normal_balance === 'CR' ? ($credits - $debits) : ($debits - $credits);
            $category = $account->account_type_category;
            if ($category === AccountType::CATEGORY_REVENUE) {
                $net += $amount;
            } elseif ($category === AccountType::CATEGORY_EXPENSE) {
                $net -= $amount;
            }
        }
        return $net;
    }

    /**
     * Corporate/government-style PDF wrapper: header bar, title, period, body, footer.
     */
    private function buildReportPdfHtml(string $reportTitle, string $periodText, string $bodyHtml): string
    {
        $generated = now()->format('F j, Y \a\t g:i A');
        $css = '
            * { box-sizing: border-box; }
            body { font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 20px; line-height: 1.4; }
            .report-header { background: linear-gradient(180deg, #1e3a5f 0%, #0f172a 100%); color: #fff; padding: 16px 24px; margin: -20px -20px 20px -20px; border-bottom: 3px solid #334155; }
            .report-header h1 { margin: 0; font-size: 18px; font-weight: 700; letter-spacing: 0.02em; }
            .report-header .sub { margin-top: 4px; font-size: 12px; opacity: 0.9; }
            .report-meta { margin-bottom: 16px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #1e3a5f; font-size: 11px; }
            .report-meta strong { color: #0f172a; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
            th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
            th { background: #1e3a5f; color: #fff; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; }
            td { font-size: 11px; }
            .text-end { text-align: right; }
            tfoot td, tr.table-total td { background: #f1f5f9; font-weight: 700; border-top: 2px solid #334155; }
            .section-title { font-size: 12px; font-weight: 700; color: #1e3a5f; margin: 14px 0 6px 0; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
            .report-footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #64748b; }
        ';
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . e($reportTitle) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="report-header"><h1>Financial Report</h1><div class="sub">' . e($reportTitle) . '</div></div>'
            . '<div class="report-meta"><strong>Period:</strong> ' . e($periodText) . ' &nbsp;|&nbsp; <strong>Generated:</strong> ' . e($generated) . '</div>'
            . $bodyHtml
            . '<div class="report-footer">Generated by CPC Accounting System &middot; ' . e($generated) . '</div></body></html>';
    }

    /** For PDF: formatted with thousand separators (e.g. 1,234.56). */
    private function formatCurrencyReport(float $num): string
    {
        return number_format(round($num, 2), 2);
    }

    /** For CSV/Excel: no thousand separators so values fit column width and don't show as ########. */
    private function formatCurrencyReportCsv(float|int $num): string
    {
        return number_format(round((float) $num, 2), 2, '.', '');
    }

    /**
     * Export Trial Balance as PDF (HTML for print).
     */
    public function exportTrialBalancePdf(Request $request): JsonResponse
    {
        $data = $this->trialBalance($request)->getData(true);
        $accounts = $data['accounts'] ?? [];
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $periodText = $startDate && $endDate ? $startDate . ' to ' . $endDate : ($endDate ? 'As of ' . $endDate : 'All periods');
        $totalDebit = array_sum(array_column($accounts, 'debit_balance'));
        $totalCredit = array_sum(array_column($accounts, 'credit_balance'));

        $rows = '';
        foreach ($accounts as $a) {
            $rows .= '<tr><td>' . e($a['account_code']) . '</td><td>' . e($a['account_name']) . '</td><td class="text-end">' . $this->formatCurrencyReport($a['debit_balance']) . '</td><td class="text-end">' . $this->formatCurrencyReport($a['credit_balance']) . '</td></tr>';
        }
        $body = '<table><thead><tr><th>Account Code</th><th>Account Name</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>' . $rows . '</tbody><tfoot><tr><td colspan="2"><strong>Total</strong></td><td class="text-end"><strong>' . $this->formatCurrencyReport($totalDebit) . '</strong></td><td class="text-end"><strong>' . $this->formatCurrencyReport($totalCredit) . '</strong></td></tr></tfoot></table>';
        $html = $this->buildReportPdfHtml('Trial Balance', $periodText, $body);
        $filename = 'trial_balance_' . now()->format('Y-m-d_His') . '.pdf';
        return response()->json(['html' => $html, 'filename' => $filename]);
    }

    /**
     * Export Trial Balance as Excel (CSV).
     */
    public function exportTrialBalanceExcel(Request $request): StreamedResponse
    {
        $data = $this->trialBalance($request)->getData(true);
        $accounts = $data['accounts'] ?? [];
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $filename = 'trial_balance_' . now()->format('Y-m-d_His') . '.csv';
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"'];
        return response()->streamDownload(function () use ($accounts, $startDate, $endDate) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Trial Balance']);
            fputcsv($out, ['Period: ' . ($startDate && $endDate ? $startDate . ' to ' . $endDate : ($endDate ? 'As of ' . $endDate : 'All periods'))]);
            fputcsv($out, ['Generated: ' . now()->format('Y-m-d H:i:s')]);
            fputcsv($out, []);
            fputcsv($out, ['Account Code', 'Account Name', 'Debit', 'Credit']);
            foreach ($accounts as $a) {
                fputcsv($out, [$a['account_code'], $a['account_name'], $this->formatCurrencyReportCsv($a['debit_balance']), $this->formatCurrencyReportCsv($a['credit_balance'])]);
            }
            $totalDebit = array_sum(array_column($accounts, 'debit_balance'));
            $totalCredit = array_sum(array_column($accounts, 'credit_balance'));
            fputcsv($out, ['Total', '', $this->formatCurrencyReportCsv($totalDebit), $this->formatCurrencyReportCsv($totalCredit)]);
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Export Income Statement as PDF.
     */
    public function exportIncomeStatementPdf(Request $request): JsonResponse
    {
        $data = $this->incomeStatement($request)->getData(true);
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $periodText = $startDate && $endDate ? $startDate . ' to ' . $endDate : ($endDate ? 'As of ' . $endDate : 'All periods');
        $sections = $data['sections'] ?? [];
        $lines = $data['lines'] ?? [];
        $totals = $data['totals'] ?? [];
        $sectionOrder = ['revenue', 'expense'];
        $sectionLabels = ['revenue' => 'Revenue', 'expense' => 'Expenses'];

        $body = '';
        foreach ($sectionOrder as $key) {
            if (!isset($sections[$key])) continue;
            $sectionLines = array_filter($lines, fn($l) => ($l['account_type'] ?? '') === $key && ($l['amount'] ?? 0) != 0);
            $total = $sections[$key]['total'] ?? 0;
            $label = $sectionLabels[$key] ?? $key;
            $body .= '<div class="section-title">' . e($label) . '</div><table><tbody>';
            foreach ($sectionLines as $l) {
                $body .= '<tr><td>' . e($l['account_code'] . ' — ' . $l['account_name']) . '</td><td class="text-end">' . $this->formatCurrencyReport($l['amount']) . '</td></tr>';
            }
            $body .= '<tr class="table-total"><td><strong>Total ' . e($label) . '</strong></td><td class="text-end"><strong>' . $this->formatCurrencyReport($total) . '</strong></td></tr></tbody></table>';
        }
        $body .= '<table><tbody><tr><td><strong>Net Income</strong></td><td class="text-end"><strong>' . $this->formatCurrencyReport($totals['net_income'] ?? 0) . '</strong></td></tr></tbody></table>';
        $html = $this->buildReportPdfHtml('Income Statement', $periodText, $body);
        return response()->json(['html' => $html, 'filename' => 'income_statement_' . now()->format('Y-m-d_His') . '.pdf']);
    }

    /**
     * Export Income Statement as Excel (CSV).
     */
    public function exportIncomeStatementExcel(Request $request): StreamedResponse
    {
        $data = $this->incomeStatement($request)->getData(true);
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $sections = $data['sections'] ?? [];
        $lines = $data['lines'] ?? [];
        $totals = $data['totals'] ?? [];
        $sectionOrder = ['revenue', 'expense'];
        $sectionLabels = ['revenue' => 'Revenue', 'expense' => 'Expenses'];
        $filename = 'income_statement_' . now()->format('Y-m-d_His') . '.csv';
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"'];
        return response()->streamDownload(function () use ($startDate, $endDate, $sections, $lines, $totals, $sectionOrder, $sectionLabels) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Income Statement']);
            fputcsv($out, ['Period: ' . ($startDate && $endDate ? $startDate . ' to ' . $endDate : ($endDate ? 'As of ' . $endDate : 'All periods'))]);
            fputcsv($out, ['Generated: ' . now()->format('Y-m-d H:i:s')]);
            fputcsv($out, []);
            foreach ($sectionOrder as $key) {
                if (!isset($sections[$key])) continue;
                $sectionLines = array_filter($lines, fn($l) => ($l['account_type'] ?? '') === $key && ($l['amount'] ?? 0) != 0);
                $total = $sections[$key]['total'] ?? 0;
                $label = $sectionLabels[$key] ?? $key;
                fputcsv($out, [$label]);
                fputcsv($out, ['Account', 'Amount']);
                foreach ($sectionLines as $l) {
                    fputcsv($out, [$l['account_code'] . ' - ' . $l['account_name'], $this->formatCurrencyReportCsv($l['amount'])]);
                }
                fputcsv($out, ['Total ' . $label, $this->formatCurrencyReportCsv($total)]);
                fputcsv($out, []);
            }
            fputcsv($out, ['Net Income', $this->formatCurrencyReportCsv($totals['net_income'] ?? 0)]);
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Export Balance Sheet as PDF.
     */
    public function exportBalanceSheetPdf(Request $request): JsonResponse
    {
        $data = $this->balanceSheet($request)->getData(true);
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $periodText = $startDate && $endDate ? $startDate . ' to ' . $endDate : ($endDate ? 'As of ' . $endDate : 'All periods');
        $sections = $data['sections'] ?? [];
        $lines = $data['lines'] ?? [];
        $totals = $data['totals'] ?? [];
        $sectionOrder = ['asset', 'liability', 'equity'];
        $sectionLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'];

        $body = '';
        foreach ($sectionOrder as $key) {
            if (!isset($sections[$key])) continue;
            $sectionLines = array_filter($lines, fn($l) => ($l['account_type'] ?? '') === $key && ($l['balance'] ?? 0) != 0);
            $total = $sections[$key]['total'] ?? 0;
            $label = $sectionLabels[$key] ?? $key;
            $body .= '<div class="section-title">' . e($label) . '</div><table><tbody>';
            foreach ($sectionLines as $l) {
                $body .= '<tr><td>' . e($l['account_code'] . ' — ' . $l['account_name']) . '</td><td class="text-end">' . $this->formatCurrencyReport($l['balance']) . '</td></tr>';
            }
            $body .= '<tr class="table-total"><td><strong>Total ' . e($label) . '</strong></td><td class="text-end"><strong>' . $this->formatCurrencyReport($total) . '</strong></td></tr></tbody></table>';
        }
        $body .= '<table><tbody><tr><td><strong>Total Assets</strong></td><td class="text-end"><strong>' . $this->formatCurrencyReport($totals['assets'] ?? 0) . '</strong></td></tr>';
        $body .= '<tr><td><strong>Total Liabilities + Equity</strong></td><td class="text-end"><strong>' . $this->formatCurrencyReport($totals['liabilities_equity'] ?? 0) . '</strong></td></tr></tbody></table>';
        $html = $this->buildReportPdfHtml('Balance Sheet', $periodText, $body);
        return response()->json(['html' => $html, 'filename' => 'balance_sheet_' . now()->format('Y-m-d_His') . '.pdf']);
    }

    /**
     * Export Balance Sheet as Excel (CSV).
     */
    public function exportBalanceSheetExcel(Request $request): StreamedResponse
    {
        $data = $this->balanceSheet($request)->getData(true);
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $sections = $data['sections'] ?? [];
        $lines = $data['lines'] ?? [];
        $totals = $data['totals'] ?? [];
        $sectionOrder = ['asset', 'liability', 'equity'];
        $sectionLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'];
        $filename = 'balance_sheet_' . now()->format('Y-m-d_His') . '.csv';
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"'];
        return response()->streamDownload(function () use ($startDate, $endDate, $sections, $lines, $totals, $sectionOrder, $sectionLabels) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Balance Sheet']);
            fputcsv($out, ['Period: ' . ($startDate && $endDate ? $startDate . ' to ' . $endDate : ($endDate ? 'As of ' . $endDate : 'All periods'))]);
            fputcsv($out, ['Generated: ' . now()->format('Y-m-d H:i:s')]);
            fputcsv($out, []);
            foreach ($sectionOrder as $key) {
                if (!isset($sections[$key])) continue;
                $sectionLines = array_filter($lines, fn($l) => ($l['account_type'] ?? '') === $key && ($l['balance'] ?? 0) != 0);
                $total = $sections[$key]['total'] ?? 0;
                $label = $sectionLabels[$key] ?? $key;
                fputcsv($out, [$label]);
                fputcsv($out, ['Account', 'Balance']);
                foreach ($sectionLines as $l) {
                    fputcsv($out, [$l['account_code'] . ' - ' . $l['account_name'], $this->formatCurrencyReportCsv($l['balance'])]);
                }
                fputcsv($out, ['Total ' . $label, $this->formatCurrencyReportCsv($total)]);
                fputcsv($out, []);
            }
            fputcsv($out, ['Total Assets', $this->formatCurrencyReportCsv($totals['assets'] ?? 0)]);
            fputcsv($out, ['Total Liabilities + Equity', $this->formatCurrencyReportCsv($totals['liabilities_equity'] ?? 0)]);
            fclose($out);
        }, $filename, $headers);
    }
}



