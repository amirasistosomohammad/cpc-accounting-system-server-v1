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
        Schema::table('bills', function (Blueprint $table) {
            $table->timestamp('converted_to_expense_at')->nullable()->after('status');
            $table->unsignedBigInteger('converted_expense_account_id')->nullable()->after('converted_to_expense_at');
            $table->unsignedBigInteger('conversion_journal_entry_id')->nullable()->after('converted_expense_account_id');
            
            $table->foreign('converted_expense_account_id')
                ->references('id')
                ->on('chart_of_accounts')
                ->onDelete('set null');
            
            $table->foreign('conversion_journal_entry_id')
                ->references('id')
                ->on('journal_entries')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['converted_expense_account_id']);
            $table->dropForeign(['conversion_journal_entry_id']);
            $table->dropColumn(['converted_to_expense_at', 'converted_expense_account_id', 'conversion_journal_entry_id']);
        });
    }
};
