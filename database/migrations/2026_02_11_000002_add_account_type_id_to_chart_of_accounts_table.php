<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure account_types table exists (should be created by previous migration)
        if (!Schema::hasTable('account_types')) {
            throw new \Exception('account_types table must exist before adding account_type_id to chart_of_accounts');
        }

        // Add account_type_id column if it doesn't exist
        if (!Schema::hasColumn('chart_of_accounts', 'account_type_id')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->foreignId('account_type_id')->nullable()->after('account_type')->constrained('account_types')->onDelete('restrict');
            });
        }

        // Migrate existing account_type enum values to account_type_id
        $typeMapping = [
            'ASSETS' => 'ASSETS',
            'LIABILITIES' => 'LIABILITIES',
            'EQUITY' => 'EQUITY',
            'REVENUE' => 'REVENUE',
            'COST_OF_SERVICES' => 'COST_OF_SERVICES',
            'OPERATING_EXPENSES' => 'OPERATING_EXPENSES',
            'OTHER_INCOME_EXPENSES' => 'OTHER_INCOME_EXPENSES',
        ];

        foreach ($typeMapping as $enumValue => $code) {
            $accountType = DB::table('account_types')->where('code', $code)->first();
            if ($accountType) {
                DB::table('chart_of_accounts')
                    ->where('account_type', $enumValue)
                    ->whereNull('account_type_id')
                    ->update(['account_type_id' => $accountType->id]);
            }
        }

        // Check if there are any NULL account_type_id values before making it NOT NULL
        $nullCount = DB::table('chart_of_accounts')->whereNull('account_type_id')->count();
        
        if ($nullCount > 0) {
            // If there are NULL values, log a warning but don't fail
            // This can happen if there are account_type enum values that don't match any account_types
            \Log::warning("Found {$nullCount} chart_of_accounts rows with NULL account_type_id. These will remain nullable.");
        } else {
            // Only make account_type_id not nullable if all rows have been migrated
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->foreignId('account_type_id')->nullable(false)->change();
            });
        }

        // Optionally drop the old account_type enum column (commented out for safety)
        // Schema::table('chart_of_accounts', function (Blueprint $table) {
        //     $table->dropColumn('account_type');
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('chart_of_accounts', 'account_type_id')) {
                $table->dropForeign(['account_type_id']);
                $table->dropColumn('account_type_id');
            }
        });
    }
};
