<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Create default account and assign all admins/personnel to it; backfill account_id on all business data.
     */
    public function up(): void
    {
        $defaultId = 1;

        if (DB::table('accounts')->where('id', $defaultId)->exists()) {
            return;
        }

        DB::table('accounts')->insert([
            'id' => $defaultId,
            'name' => 'CPC Growth Strategies, Inc.',
            'code' => 'CPC-MAIN',
            'settings' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (DB::table('admins')->pluck('id') as $adminId) {
            DB::table('account_user')->insertOrIgnore([
                'account_id' => $defaultId,
                'user_type' => 'App\Models\Admin',
                'user_id' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('personnel')->pluck('id') as $personnelId) {
            DB::table('account_user')->insertOrIgnore([
                'account_id' => $defaultId,
                'user_type' => 'App\Models\Personnel',
                'user_id' => $personnelId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tables = ['chart_of_accounts', 'journal_entries', 'clients', 'suppliers', 'invoices', 'bills', 'payments'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'account_id')) {
                DB::table($table)->whereNull('account_id')->update(['account_id' => $defaultId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('account_user')->where('account_id', 1)->delete();
        DB::table('accounts')->where('id', 1)->delete();
    }
};
