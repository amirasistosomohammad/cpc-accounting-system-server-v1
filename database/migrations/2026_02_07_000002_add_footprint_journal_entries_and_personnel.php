<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                if (!Schema::hasColumn('journal_entries', 'updated_by_type')) {
                    $table->string('updated_by_type', 32)->nullable()->after('created_by_type');
                }
                if (!Schema::hasColumn('journal_entries', 'updated_by_id')) {
                    $table->unsignedBigInteger('updated_by_id')->nullable()->after('updated_by_type');
                }
            });
        }

        if (Schema::hasTable('personnel')) {
            Schema::table('personnel', function (Blueprint $table) {
                if (!Schema::hasColumn('personnel', 'created_by_type')) {
                    $table->string('created_by_type', 32)->nullable()->after('updated_at');
                }
                if (!Schema::hasColumn('personnel', 'created_by_id')) {
                    $table->unsignedBigInteger('created_by_id')->nullable()->after('created_by_type');
                }
                if (!Schema::hasColumn('personnel', 'updated_by_type')) {
                    $table->string('updated_by_type', 32)->nullable()->after('created_by_id');
                }
                if (!Schema::hasColumn('personnel', 'updated_by_id')) {
                    $table->unsignedBigInteger('updated_by_id')->nullable()->after('updated_by_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                if (Schema::hasColumn('journal_entries', 'updated_by_type')) {
                    $table->dropColumn('updated_by_type');
                }
                if (Schema::hasColumn('journal_entries', 'updated_by_id')) {
                    $table->dropColumn('updated_by_id');
                }
            });
        }

        if (Schema::hasTable('personnel')) {
            Schema::table('personnel', function (Blueprint $table) {
                foreach (['created_by_type', 'created_by_id', 'updated_by_type', 'updated_by_id'] as $col) {
                    if (Schema::hasColumn('personnel', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
