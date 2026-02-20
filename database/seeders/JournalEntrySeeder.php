<?php

namespace Database\Seeders;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JournalEntrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get accounts for creating entries
        $accounts = ChartOfAccount::all();
        
        if ($accounts->isEmpty()) {
            $this->command->warn('No chart of accounts found. Please run ChartOfAccountSeeder first.');
            return;
        }

        // Get specific accounts for common transactions
        $cashAccount = $accounts->where('account_code', '1020')->first() ?? $accounts->where('account_type', 'ASSETS')->first();
        $arAccount = $accounts->where('account_code', '1040')->first() ?? $accounts->where('account_type', 'ASSETS')->skip(1)->first();
        $revenueAccount = $accounts->where('account_type', 'REVENUE')->first();
        $expenseAccount = $accounts->where('account_type', 'OPERATING_EXPENSES')->first();
        $apAccount = $accounts->where('account_code', '2010')->first() ?? $accounts->where('account_type', 'LIABILITIES')->first();
        $equityAccount = $accounts->where('account_type', 'EQUITY')->first();

        if (!$cashAccount || !$revenueAccount || !$expenseAccount) {
            $this->command->warn('Required accounts not found. Using any available accounts.');
            $cashAccount = $accounts->first();
            $revenueAccount = $accounts->skip(1)->first();
            $expenseAccount = $accounts->skip(2)->first();
        }

        $entries = [
            // Entry 1: Large revenue transaction
            [
                'entry_date' => Carbon::now()->subDays(30),
                'description' => 'Large Marketing Consultancy Revenue - Q1 Project',
                'reference_number' => 'INV-2024-001',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 50000000.00, 'credit' => 0, 'description' => 'Cash received'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 50000000.00, 'description' => 'Consultancy revenue'],
                ],
            ],
            // Entry 2: Very large real estate commission
            [
                'entry_date' => Carbon::now()->subDays(25),
                'description' => 'Real Estate Commission - Luxury Property Sale',
                'reference_number' => 'COM-2024-045',
                'lines' => [
                    ['account' => $arAccount ?? $cashAccount, 'debit' => 125000000.00, 'credit' => 0, 'description' => 'Commission receivable'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 125000000.00, 'description' => 'Real estate commission income'],
                ],
            ],
            // Entry 3: Large expense transaction
            [
                'entry_date' => Carbon::now()->subDays(20),
                'description' => 'Major Office Equipment Purchase and Setup',
                'reference_number' => 'PO-2024-789',
                'lines' => [
                    ['account' => $accounts->where('account_code', '1210')->first() ?? $accounts->where('account_type', 'ASSETS')->skip(2)->first(), 'debit' => 35000000.00, 'credit' => 0, 'description' => 'Office equipment'],
                    ['account' => $apAccount ?? $accounts->where('account_type', 'LIABILITIES')->first(), 'debit' => 0, 'credit' => 35000000.00, 'description' => 'Accounts payable'],
                ],
            ],
            // Entry 4: Large salary payment
            [
                'entry_date' => Carbon::now()->subDays(15),
                'description' => 'Monthly Payroll - All Staff and Consultants',
                'reference_number' => 'PAY-2024-012',
                'lines' => [
                    ['account' => $expenseAccount, 'debit' => 28000000.00, 'credit' => 0, 'description' => 'Salaries and wages expense'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 28000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 5: Large client payment received
            [
                'entry_date' => Carbon::now()->subDays(10),
                'description' => 'Payment Received - Construction Referral Project',
                'reference_number' => 'PAY-REC-2024-156',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 75000000.00, 'credit' => 0, 'description' => 'Cash received'],
                    ['account' => $arAccount ?? $cashAccount, 'debit' => 0, 'credit' => 75000000.00, 'description' => 'Accounts receivable cleared'],
                ],
            ],
            // Entry 6: Large supplier payment
            [
                'entry_date' => Carbon::now()->subDays(8),
                'description' => 'Payment to Major Supplier - Equipment and Materials',
                'reference_number' => 'CHK-2024-234',
                'lines' => [
                    ['account' => $apAccount ?? $accounts->where('account_type', 'LIABILITIES')->first(), 'debit' => 42000000.00, 'credit' => 0, 'description' => 'Accounts payable cleared'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 42000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 7: Large advertising expense
            [
                'entry_date' => Carbon::now()->subDays(5),
                'description' => 'Major Advertising Campaign - Digital and Print Media',
                'reference_number' => 'ADV-2024-089',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6110')->first() ?? $expenseAccount, 'debit' => 15000000.00, 'credit' => 0, 'description' => 'Advertising expense'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 15000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 8: Large rental income
            [
                'entry_date' => Carbon::now()->subDays(3),
                'description' => 'Rental Commission Income - Multiple Properties',
                'reference_number' => 'RENT-2024-567',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 95000000.00, 'credit' => 0, 'description' => 'Cash received'],
                    ['account' => $accounts->where('account_code', '4060')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 95000000.00, 'description' => 'Rental commission income'],
                ],
            ],
            // Entry 9: Large professional fees
            [
                'entry_date' => Carbon::now()->subDays(2),
                'description' => 'Professional Services - Legal and Accounting Fees',
                'reference_number' => 'PROF-2024-123',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6020')->first() ?? $expenseAccount, 'debit' => 18000000.00, 'credit' => 0, 'description' => 'Professional fees expense'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 18000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 10: Very large transaction - multi-line
            [
                'entry_date' => Carbon::now()->subDay(),
                'description' => 'Complex Transaction - Multiple Revenue Streams and Expenses',
                'reference_number' => 'MULTI-2024-999',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 200000000.00, 'credit' => 0, 'description' => 'Total cash received'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 120000000.00, 'description' => 'Primary revenue'],
                    ['account' => $accounts->where('account_code', '4020')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 80000000.00, 'description' => 'Secondary revenue'],
                ],
            ],
            // Entry 11: Large equipment purchase with depreciation
            [
                'entry_date' => Carbon::now()->subDays(12),
                'description' => 'Computer Equipment Purchase - IT Infrastructure Upgrade',
                'reference_number' => 'IT-2024-456',
                'lines' => [
                    ['account' => $accounts->where('account_code', '1230')->first() ?? $accounts->where('account_type', 'ASSETS')->skip(2)->first(), 'debit' => 45000000.00, 'credit' => 0, 'description' => 'Computer equipment'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 45000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 12: Large utility and rent expenses
            [
                'entry_date' => Carbon::now()->subDays(7),
                'description' => 'Quarterly Utilities and Office Rent Payment',
                'reference_number' => 'UTIL-2024-321',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6210')->first() ?? $expenseAccount, 'debit' => 8500000.00, 'credit' => 0, 'description' => 'Utilities expense'],
                    ['account' => $accounts->where('account_code', '6220')->first() ?? $expenseAccount, 'debit' => 12000000.00, 'credit' => 0, 'description' => 'Rent expense'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 20500000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 13: Large miscellaneous income
            [
                'entry_date' => Carbon::now()->subDays(4),
                'description' => 'Other Service Income - Various Projects',
                'reference_number' => 'MISC-2024-654',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 65000000.00, 'credit' => 0, 'description' => 'Cash received'],
                    ['account' => $accounts->where('account_code', '4070')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 65000000.00, 'description' => 'Other service income'],
                ],
            ],
            // Entry 14: Large transportation and representation
            [
                'entry_date' => Carbon::now()->subDays(6),
                'description' => 'Business Travel and Client Representation Expenses',
                'reference_number' => 'TRAVEL-2024-987',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6040')->first() ?? $expenseAccount, 'debit' => 12000000.00, 'credit' => 0, 'description' => 'Transportation expense'],
                    ['account' => $accounts->where('account_code', '6050')->first() ?? $expenseAccount, 'debit' => 8000000.00, 'credit' => 0, 'description' => 'Representation expense'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 20000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 15: Very large transaction - hundreds of millions
            [
                'entry_date' => Carbon::now()->subDays(1),
                'description' => 'Mega Project Revenue - Annual Contract Payment',
                'reference_number' => 'MEGA-2024-001',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 500000000.00, 'credit' => 0, 'description' => 'Cash received'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 500000000.00, 'description' => 'Annual contract revenue'],
                ],
            ],
        ];

        DB::beginTransaction();
        try {
            foreach ($entries as $entryData) {
                // Calculate totals
                $totalDebit = collect($entryData['lines'])->sum('debit');
                $totalCredit = collect($entryData['lines'])->sum('credit');

                // Create journal entry
                $entry = JournalEntry::create([
                    'entry_number' => JournalEntry::generateEntryNumber(),
                    'entry_date' => $entryData['entry_date'],
                    'description' => $entryData['description'],
                    'reference_number' => $entryData['reference_number'],
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'created_by' => null,
                ]);

                // Create journal entry lines
                foreach ($entryData['lines'] as $lineData) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $lineData['account']->id,
                        'debit_amount' => $lineData['debit'],
                        'credit_amount' => $lineData['credit'],
                        'description' => $lineData['description'],
                    ]);
                }
            }

            DB::commit();
            $this->command->info('Successfully seeded ' . count($entries) . ' journal entries with large numbers.');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to seed journal entries: ' . $e->getMessage());
            throw $e;
        }
    }
}

