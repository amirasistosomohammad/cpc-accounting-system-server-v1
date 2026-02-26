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
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                // Simple, portable indexes for faster date/account filtering.
                // Migration runs once per database, so we don't need Doctrine.
                if (Schema::hasColumn('journal_entries', 'entry_date')) {
                    $table->index('entry_date', 'je_entry_date_index');
                }

                if (Schema::hasColumn('journal_entries', 'account_id')) {
                    $table->index(['account_id', 'entry_date'], 'je_account_id_entry_date_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                // Drop indexes by their explicit names
                $table->dropIndex('je_entry_date_index');
                $table->dropIndex('je_account_id_entry_date_index');
            });
        }
    }
};

