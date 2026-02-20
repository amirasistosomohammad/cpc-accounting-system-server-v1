<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = [
            'chart_of_accounts',
            'journal_entries',
            'clients',
            'suppliers',
            'invoices',
            'bills',
            'payments',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'account_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('account_id')->nullable()->after('id')->constrained('accounts')->cascadeOnDelete();
                });
            }
        }

        // journal_entry_lines: do NOT add entity account_id - it already has account_id for chart_of_accounts
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'chart_of_accounts',
            'journal_entries',
            'clients',
            'suppliers',
            'invoices',
            'bills',
            'payments',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'account_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropForeign(['account_id']);
                });
            }
        }
    }
};
