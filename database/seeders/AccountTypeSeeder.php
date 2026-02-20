<?php

namespace Database\Seeders;

use App\Models\AccountType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accountId = DB::table('accounts')->orderBy('id')->value('id');
        if (!$accountId) {
            $this->command->warn('No account found. Create an account first (e.g. run migration that creates default account).');
            return;
        }

        $accountTypes = [
            [
                'code' => 'ASSETS',
                'name' => 'Assets',
                'normal_balance' => 'DR',
                'category' => 'asset',
                'color' => '#28a745',
                'icon' => 'FaWallet',
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'LIABILITIES',
                'name' => 'Liabilities',
                'normal_balance' => 'CR',
                'category' => 'liability',
                'color' => '#ffc107',
                'icon' => 'FaFileInvoice',
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'EQUITY',
                'name' => 'Equity',
                'normal_balance' => 'CR',
                'category' => 'equity',
                'color' => '#17a2b8',
                'icon' => 'FaBalanceScale',
                'display_order' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'REVENUE',
                'name' => 'Revenue',
                'normal_balance' => 'CR',
                'category' => 'revenue',
                'color' => '#007bff',
                'icon' => 'FaArrowUp',
                'display_order' => 4,
                'is_active' => true,
            ],
            [
                'code' => 'COST_OF_SERVICES',
                'name' => 'Cost of Services',
                'normal_balance' => 'DR',
                'category' => 'expense',
                'color' => '#dc3545',
                'icon' => 'FaArrowDown',
                'display_order' => 5,
                'is_active' => true,
            ],
            [
                'code' => 'OPERATING_EXPENSES',
                'name' => 'Operating Expenses',
                'normal_balance' => 'DR',
                'category' => 'expense',
                'color' => '#dc3545',
                'icon' => 'FaArrowDown',
                'display_order' => 6,
                'is_active' => true,
            ],
        ];

        foreach ($accountTypes as $type) {
            AccountType::updateOrCreate(
                ['account_id' => $accountId, 'code' => $type['code']],
                array_merge($type, ['account_id' => $accountId])
            );
        }
    }
}
