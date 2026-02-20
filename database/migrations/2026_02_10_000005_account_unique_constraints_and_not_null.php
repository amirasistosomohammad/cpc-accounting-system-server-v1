<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Run AFTER backfill (000004). Adds composite uniques and makes account_id not null.
     */
    public function up(): void
    {
        // chart_of_accounts: drop unique account_code, add unique(account_id, account_code)
        if (Schema::hasTable('chart_of_accounts')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->dropUnique(['account_code']);
                $table->unique(['account_id', 'account_code']);
                $table->foreignId('account_id')->nullable(false)->change();
            });
        }

        // journal_entries: drop unique entry_number, add unique(account_id, entry_number)
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropUnique(['entry_number']);
                $table->unique(['account_id', 'entry_number']);
                $table->foreignId('account_id')->nullable(false)->change();
            });
        }

        foreach (['clients', 'suppliers', 'invoices', 'bills', 'payments'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('account_id')->nullable(false)->change();
                });
            }
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropUnique(['invoice_number']);
                $table->unique(['account_id', 'invoice_number']);
            });
        }

        if (Schema::hasTable('bills')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropUnique(['bill_number']);
                $table->unique(['account_id', 'bill_number']);
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(['payment_number']);
                $table->unique(['account_id', 'payment_number']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chart_of_accounts')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->dropUnique(['account_id', 'account_code']);
                $table->unique('account_code');
                $table->foreignId('account_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropUnique(['account_id', 'entry_number']);
                $table->unique('entry_number');
                $table->foreignId('account_id')->nullable()->change();
            });
        }

        foreach (['clients', 'suppliers'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('account_id')->nullable()->change();
                });
            }
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropUnique(['account_id', 'invoice_number']);
                $table->unique('invoice_number');
                $table->foreignId('account_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('bills')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropUnique(['account_id', 'bill_number']);
                $table->unique('bill_number');
                $table->foreignId('account_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(['account_id', 'payment_number']);
                $table->unique('payment_number');
                $table->foreignId('account_id')->nullable()->change();
            });
        }
    }
};
