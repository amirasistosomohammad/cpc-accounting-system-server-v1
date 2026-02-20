<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'clients' => ['created_by_type', 'created_by_id', 'updated_by_type', 'updated_by_id'],
            'invoices' => ['created_by_type', 'created_by_id', 'updated_by_type', 'updated_by_id'],
            'payments' => ['created_by_type', 'created_by_id'],
            'bills' => ['created_by_type', 'created_by_id', 'updated_by_type', 'updated_by_id'],
            'suppliers' => ['created_by_type', 'created_by_id', 'updated_by_type', 'updated_by_id'],
        ];

        foreach ($tables as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($columns, $tableName) {
                if (in_array('created_by_type', $columns) && !Schema::hasColumn($tableName, 'created_by_type')) {
                    $table->string('created_by_type', 32)->nullable()->after('updated_at');
                }
                if (in_array('created_by_id', $columns) && !Schema::hasColumn($tableName, 'created_by_id')) {
                    $table->unsignedBigInteger('created_by_id')->nullable()->after('created_by_type');
                }
                if (in_array('updated_by_type', $columns) && !Schema::hasColumn($tableName, 'updated_by_type')) {
                    $table->string('updated_by_type', 32)->nullable()->after('created_by_id');
                }
                if (in_array('updated_by_id', $columns) && !Schema::hasColumn($tableName, 'updated_by_id')) {
                    $table->unsignedBigInteger('updated_by_id')->nullable()->after('updated_by_type');
                }
            });
        }

        // journal_entries has created_by already; add created_by_type
        if (Schema::hasTable('journal_entries') && !Schema::hasColumn('journal_entries', 'created_by_type')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->string('created_by_type', 32)->nullable()->after('created_by');
            });
        }
    }

    public function down(): void
    {
        $tables = ['clients', 'invoices', 'payments', 'bills', 'suppliers'];
        foreach ($tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $cols = ['created_by_type', 'created_by_id', 'updated_by_type', 'updated_by_id'];
                foreach ($cols as $c) {
                    if (Schema::hasColumn($tableName, $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('journal_entries') && Schema::hasColumn('journal_entries', 'created_by_type')) {
            Schema::table('journal_entries', fn (Blueprint $table) => $table->dropColumn('created_by_type'));
        }
    }
};
