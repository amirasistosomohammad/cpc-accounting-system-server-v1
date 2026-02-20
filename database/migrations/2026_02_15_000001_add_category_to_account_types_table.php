<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Category determines how the system treats the account type for filters (expense dropdown, income dropdown, reports).
     * Values: asset, liability, equity, revenue, expense, other
     */
    public function up(): void
    {
        if (!Schema::hasTable('account_types')) {
            return;
        }

        if (!Schema::hasColumn('account_types', 'category')) {
            Schema::table('account_types', function (Blueprint $table) {
                $table->string('category', 20)->nullable()->after('normal_balance');
            });
        }

        // Map existing codes to category so existing data works
        $codeToCategory = [
            'ASSETS' => 'asset',
            'LIABILITIES' => 'liability',
            'EQUITY' => 'equity',
            'REVENUE' => 'revenue',
            'COST_OF_SERVICES' => 'expense',
            'OPERATING_EXPENSES' => 'expense',
            'OTHER_INCOME_EXPENSES' => 'other',
        ];

        foreach ($codeToCategory as $code => $category) {
            DB::table('account_types')->where('code', $code)->whereNull('category')->update(['category' => $category]);
        }

        // Any remaining null → other
        DB::table('account_types')->whereNull('category')->update(['category' => 'other']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('account_types') && Schema::hasColumn('account_types', 'category')) {
            Schema::table('account_types', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
