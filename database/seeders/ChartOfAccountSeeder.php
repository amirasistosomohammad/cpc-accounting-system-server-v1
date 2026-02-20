<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\AccountType;
use Illuminate\Database\Seeder;

class ChartOfAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get account types by code
        $accountTypes = AccountType::all()->keyBy('code');
        
        // Get default account_id (assuming account_id=1 exists from backfill migration)
        $defaultAccountId = 1;

        $accounts = [
            // ASSETS (1000 series)
            ['account_code' => '1010', 'account_name' => 'Cash on Hand', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1020', 'account_name' => 'Cash in Bank', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1030', 'account_name' => 'Petty Cash Fund', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1040', 'account_name' => 'Accounts Receivable - Clients', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1050', 'account_name' => 'Commission Receivable', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1060', 'account_name' => 'Advances to Suppliers', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1070', 'account_name' => 'Prepaid Expenses', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1210', 'account_name' => 'Office Equipment', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1220', 'account_name' => 'Furniture & Fixtures', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1230', 'account_name' => 'Computer & IT Equipment', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1240', 'account_name' => 'Accumulated Depreciation', 'account_type_code' => 'ASSETS', 'normal_balance' => 'CR'],
            ['account_code' => '1250', 'account_name' => 'Software & Licenses', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],

            // LIABILITIES (2000 series)
            ['account_code' => '2010', 'account_name' => 'Accounts Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2020', 'account_name' => 'Accrued Expenses', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2030', 'account_name' => 'Taxes Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2040', 'account_name' => 'Withholding Tax Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2050', 'account_name' => 'Advances from Clients', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2060', 'account_name' => 'Commission Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2210', 'account_name' => 'Bank Loan Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2220', 'account_name' => 'Loans Payable - Officers', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],

            // EQUITY (3000 series)
            ['account_code' => '3010', 'account_name' => "Owner's Capital/Paid-In Capital", 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3020', 'account_name' => 'Additional Paid-In Capital', 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3030', 'account_name' => 'Retained Earnings', 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3040', 'account_name' => 'Current Year Net Income', 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3050', 'account_name' => "Owner's Drawings", 'account_type_code' => 'EQUITY', 'normal_balance' => 'DR'],

            // REVENUE/INCOME (4000 series)
            ['account_code' => '4010', 'account_name' => 'Marketing Consultancy Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4020', 'account_name' => 'Real Estate Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4030', 'account_name' => 'Construction Referral Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4040', 'account_name' => 'Memorial Lot Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4050', 'account_name' => 'Food Business Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4060', 'account_name' => 'Rental/Service Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4070', 'account_name' => 'Other Service Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],

            // COST OF SERVICES (5000 series)
            ['account_code' => '5010', 'account_name' => 'Consultant Fees', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],
            ['account_code' => '5020', 'account_name' => 'Agent Commission Expense', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],
            ['account_code' => '5030', 'account_name' => 'Project-Based Labor', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],
            ['account_code' => '5040', 'account_name' => 'Outsourced Services', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],

            // OPERATING EXPENSES (6000 series)
            ['account_code' => '6010', 'account_name' => 'Salaries & Wages', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6020', 'account_name' => 'Professional Fees', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6030', 'account_name' => 'Office Supplies Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6040', 'account_name' => 'Transportation & Travel', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6050', 'account_name' => 'Representation Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6110', 'account_name' => 'Advertising & Promotions', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6120', 'account_name' => 'Social Media Marketing', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6130', 'account_name' => 'Printing & Flyers', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6210', 'account_name' => 'Utilities Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6220', 'account_name' => 'Rent Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6230', 'account_name' => 'Communication Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6240', 'account_name' => 'Internet & Software Subscription', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6250', 'account_name' => 'Repairs & Maintenance', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6260', 'account_name' => 'Depreciation Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6270', 'account_name' => 'Miscellaneous Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],

            // Non-operating income / expense (7000 series) — mapped to REVENUE / OPERATING_EXPENSES
            ['account_code' => '7010', 'account_name' => 'Interest Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '7020', 'account_name' => 'Other Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '7030', 'account_name' => 'Interest Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '7040', 'account_name' => 'Bank Charges', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
        ];

        foreach ($accounts as $accountData) {
            $accountTypeCode = $accountData['account_type_code'];
            unset($accountData['account_type_code']);
            
            $accountType = $accountTypes->get($accountTypeCode);
            if (!$accountType) {
                $this->command->warn("Account type '{$accountTypeCode}' not found. Skipping account {$accountData['account_code']}.");
                continue;
            }

            ChartOfAccount::updateOrCreate(
                [
                    'account_id' => $defaultAccountId,
                    'account_code' => $accountData['account_code'],
                ],
                array_merge($accountData, [
                    'account_type_id' => $accountType->id,
                    'is_active' => true,
                ])
            );
        }
    }
}


