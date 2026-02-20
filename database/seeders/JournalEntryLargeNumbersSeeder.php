<?php

namespace Database\Seeders;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JournalEntryLargeNumbersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * This seeder creates journal entries with VERY LARGE numbers (billions) for testing responsive design.
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
            // Entry 1: Billion peso revenue transaction
            [
                'entry_date' => Carbon::now()->subDays(45),
                'description' => 'Mega Project Revenue - Billion Peso Contract',
                'reference_number' => 'BILLION-2024-001',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 1000000000.00, 'credit' => 0, 'description' => 'Cash received - 1 billion'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 1000000000.00, 'description' => 'Mega project revenue'],
                ],
            ],
            // Entry 2: Multi-billion transaction
            [
                'entry_date' => Carbon::now()->subDays(40),
                'description' => 'Major Real Estate Development - Multi-Billion Commission',
                'reference_number' => 'MEGA-RE-2024-500',
                'lines' => [
                    ['account' => $arAccount ?? $cashAccount, 'debit' => 2500000000.00, 'credit' => 0, 'description' => 'Commission receivable - 2.5 billion'],
                    ['account' => $accounts->where('account_code', '4020')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 2500000000.00, 'description' => 'Real estate commission income'],
                ],
            ],
            // Entry 3: Very large equipment purchase
            [
                'entry_date' => Carbon::now()->subDays(35),
                'description' => 'Massive Infrastructure Investment - Equipment and Facilities',
                'reference_number' => 'INFRA-2024-999',
                'lines' => [
                    ['account' => $accounts->where('account_code', '1210')->first() ?? $accounts->where('account_type', 'ASSETS')->skip(2)->first(), 'debit' => 1500000000.00, 'credit' => 0, 'description' => 'Office equipment - 1.5 billion'],
                    ['account' => $apAccount ?? $accounts->where('account_type', 'LIABILITIES')->first(), 'debit' => 0, 'credit' => 1500000000.00, 'description' => 'Accounts payable'],
                ],
            ],
            // Entry 4: Billion peso payroll
            [
                'entry_date' => Carbon::now()->subDays(30),
                'description' => 'Annual Payroll - All Employees and Consultants',
                'reference_number' => 'PAYROLL-2024-ANNUAL',
                'lines' => [
                    ['account' => $expenseAccount, 'debit' => 800000000.00, 'credit' => 0, 'description' => 'Annual salaries expense - 800 million'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 800000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 5: Multi-billion client payment
            [
                'entry_date' => Carbon::now()->subDays(25),
                'description' => 'Payment Received - Major Construction Project',
                'reference_number' => 'CONSTRUCTION-2024-888',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 3500000000.00, 'credit' => 0, 'description' => 'Cash received - 3.5 billion'],
                    ['account' => $arAccount ?? $cashAccount, 'debit' => 0, 'credit' => 3500000000.00, 'description' => 'Accounts receivable cleared'],
                ],
            ],
            // Entry 6: Billion peso supplier payment
            [
                'entry_date' => Carbon::now()->subDays(20),
                'description' => 'Payment to Major Supplier - Materials and Services',
                'reference_number' => 'SUPPLIER-2024-777',
                'lines' => [
                    ['account' => $apAccount ?? $accounts->where('account_type', 'LIABILITIES')->first(), 'debit' => 1200000000.00, 'credit' => 0, 'description' => 'Accounts payable cleared - 1.2 billion'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 1200000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 7: Large advertising campaign
            [
                'entry_date' => Carbon::now()->subDays(15),
                'description' => 'National Advertising Campaign - Multi-Media',
                'reference_number' => 'ADV-CAMPAIGN-2024',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6110')->first() ?? $expenseAccount, 'debit' => 500000000.00, 'credit' => 0, 'description' => 'Advertising expense - 500 million'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 500000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 8: Billion peso rental income
            [
                'entry_date' => Carbon::now()->subDays(10),
                'description' => 'Rental Commission Income - Multiple High-Value Properties',
                'reference_number' => 'RENTAL-MEGA-2024',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 1800000000.00, 'credit' => 0, 'description' => 'Cash received - 1.8 billion'],
                    ['account' => $accounts->where('account_code', '4060')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 1800000000.00, 'description' => 'Rental commission income'],
                ],
            ],
            // Entry 9: Large professional services
            [
                'entry_date' => Carbon::now()->subDays(8),
                'description' => 'Professional Services - Legal, Accounting, and Consulting',
                'reference_number' => 'PROF-SERVICES-2024',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6020')->first() ?? $expenseAccount, 'debit' => 450000000.00, 'credit' => 0, 'description' => 'Professional fees - 450 million'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 450000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 10: Multi-billion complex transaction
            [
                'entry_date' => Carbon::now()->subDays(5),
                'description' => 'Complex Multi-Billion Transaction - Multiple Revenue Streams',
                'reference_number' => 'MULTI-BILLION-2024',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 5000000000.00, 'credit' => 0, 'description' => 'Total cash received - 5 billion'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 3000000000.00, 'description' => 'Primary revenue - 3 billion'],
                    ['account' => $accounts->where('account_code', '4020')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 2000000000.00, 'description' => 'Secondary revenue - 2 billion'],
                ],
            ],
            // Entry 11: Massive IT infrastructure
            [
                'entry_date' => Carbon::now()->subDays(3),
                'description' => 'IT Infrastructure Upgrade - Enterprise Systems',
                'reference_number' => 'IT-INFRA-2024',
                'lines' => [
                    ['account' => $accounts->where('account_code', '1230')->first() ?? $accounts->where('account_type', 'ASSETS')->skip(2)->first(), 'debit' => 2200000000.00, 'credit' => 0, 'description' => 'Computer equipment - 2.2 billion'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 2200000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 12: Large utilities and facilities
            [
                'entry_date' => Carbon::now()->subDays(2),
                'description' => 'Annual Utilities and Facilities Maintenance',
                'reference_number' => 'FACILITIES-2024',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6210')->first() ?? $expenseAccount, 'debit' => 280000000.00, 'credit' => 0, 'description' => 'Utilities expense - 280 million'],
                    ['account' => $accounts->where('account_code', '6220')->first() ?? $expenseAccount, 'debit' => 320000000.00, 'credit' => 0, 'description' => 'Rent expense - 320 million'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 600000000.00, 'description' => 'Cash payment - 600 million'],
                ],
            ],
            // Entry 13: Billion peso miscellaneous income
            [
                'entry_date' => Carbon::now()->subDay(),
                'description' => 'Other Service Income - Various High-Value Projects',
                'reference_number' => 'MISC-BILLION-2024',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 1500000000.00, 'credit' => 0, 'description' => 'Cash received - 1.5 billion'],
                    ['account' => $accounts->where('account_code', '4070')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 1500000000.00, 'description' => 'Other service income'],
                ],
            ],
            // Entry 14: Large business expenses
            [
                'entry_date' => Carbon::now(),
                'description' => 'Comprehensive Business Expenses - Travel and Representation',
                'reference_number' => 'BIZ-EXPENSES-2024',
                'lines' => [
                    ['account' => $accounts->where('account_code', '6040')->first() ?? $expenseAccount, 'debit' => 380000000.00, 'credit' => 0, 'description' => 'Transportation - 380 million'],
                    ['account' => $accounts->where('account_code', '6050')->first() ?? $expenseAccount, 'debit' => 220000000.00, 'credit' => 0, 'description' => 'Representation - 220 million'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 600000000.00, 'description' => 'Cash payment - 600 million'],
                ],
            ],
            // Entry 15: ULTRA LARGE - 10 billion transaction
            [
                'entry_date' => Carbon::now(),
                'description' => 'Ultra Mega Project - 10 Billion Peso Annual Contract',
                'reference_number' => 'ULTRA-10B-2024',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 10000000000.00, 'credit' => 0, 'description' => 'Cash received - 10 billion'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 10000000000.00, 'description' => 'Ultra mega project revenue'],
                ],
            ],
            // Entry 16: 7.5 billion transaction
            [
                'entry_date' => Carbon::now()->subDays(1),
                'description' => 'Major Development Project - 7.5 Billion Revenue',
                'reference_number' => 'DEV-7.5B-2024',
                'lines' => [
                    ['account' => $arAccount ?? $cashAccount, 'debit' => 7500000000.00, 'credit' => 0, 'description' => 'Receivable - 7.5 billion'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 7500000000.00, 'description' => 'Development project revenue'],
                ],
            ],
            // Entry 17: 4 billion multi-line
            [
                'entry_date' => Carbon::now()->subDays(2),
                'description' => 'Complex Multi-Billion Revenue - Multiple Income Sources',
                'reference_number' => 'COMPLEX-4B-2024',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 4000000000.00, 'credit' => 0, 'description' => 'Total cash - 4 billion'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 2500000000.00, 'description' => 'Primary income - 2.5 billion'],
                    ['account' => $accounts->where('account_code', '4030')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 1500000000.00, 'description' => 'Construction referral - 1.5 billion'],
                ],
            ],
            // Entry 18: 6 billion expense
            [
                'entry_date' => Carbon::now()->subDays(3),
                'description' => 'Major Capital Expenditure - 6 Billion Investment',
                'reference_number' => 'CAPEX-6B-2024',
                'lines' => [
                    ['account' => $accounts->where('account_code', '1210')->first() ?? $accounts->where('account_type', 'ASSETS')->skip(2)->first(), 'debit' => 6000000000.00, 'credit' => 0, 'description' => 'Capital assets - 6 billion'],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => 6000000000.00, 'description' => 'Cash payment'],
                ],
            ],
            // Entry 19: 3.2 billion transaction
            [
                'entry_date' => Carbon::now()->subDays(4),
                'description' => 'Large Scale Marketing Campaign Revenue',
                'reference_number' => 'MARKETING-3.2B-2024',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 3200000000.00, 'credit' => 0, 'description' => 'Cash received - 3.2 billion'],
                    ['account' => $accounts->where('account_code', '4010')->first() ?? $revenueAccount, 'debit' => 0, 'credit' => 3200000000.00, 'description' => 'Marketing consultancy income'],
                ],
            ],
            // Entry 20: 8.8 billion - largest single line
            [
                'entry_date' => Carbon::now()->subDays(5),
                'description' => 'Record Breaking Transaction - 8.8 Billion Peso Deal',
                'reference_number' => 'RECORD-8.8B-2024',
                'lines' => [
                    ['account' => $cashAccount, 'debit' => 8800000000.00, 'credit' => 0, 'description' => 'Cash received - 8.8 billion'],
                    ['account' => $revenueAccount, 'debit' => 0, 'credit' => 8800000000.00, 'description' => 'Record breaking revenue'],
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
            $this->command->info('Successfully seeded ' . count($entries) . ' journal entries with VERY LARGE numbers (billions).');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to seed journal entries: ' . $e->getMessage());
            throw $e;
        }
    }
}

