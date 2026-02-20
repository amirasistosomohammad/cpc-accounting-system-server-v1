<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Make account types per-business so each business has its own types.
     */
    public function up(): void
    {
        if (!Schema::hasTable('account_types')) {
            return;
        }

        // Add account_id nullable first
        if (!Schema::hasColumn('account_types', 'account_id')) {
            Schema::table('account_types', function (Blueprint $table) {
                $table->foreignId('account_id')->nullable()->after('id')->constrained('accounts')->cascadeOnDelete();
            });
        }

        // Backfill: assign existing types to the first business account
        $firstAccountId = DB::table('accounts')->orderBy('id')->value('id');
        $accountIds = DB::table('accounts')->orderBy('id')->pluck('id');
        if ($firstAccountId !== null) {
            DB::table('account_types')->whereNull('account_id')->update(['account_id' => $firstAccountId]);
        }

        // Drop global unique on code before duplicating (so same code can exist per account)
        Schema::table('account_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });

        // Duplicate default types for every other business account so each has its own set
        $defaultTypes = DB::table('account_types')->where('account_id', $firstAccountId)->get();
        foreach ($accountIds as $aid) {
            if ((int) $aid === (int) $firstAccountId) {
                continue;
            }
            foreach ($defaultTypes as $t) {
                DB::table('account_types')->insert([
                    'account_id' => $aid,
                    'code' => $t->code,
                    'name' => $t->name,
                    'normal_balance' => $t->normal_balance,
                    'color' => $t->color,
                    'icon' => $t->icon,
                    'display_order' => $t->display_order,
                    'is_active' => $t->is_active,
                    'created_at' => $t->created_at,
                    'updated_at' => now(),
                ]);
            }
        }

        // Make account_id not nullable (use raw to avoid doctrine/dbal)
        DB::statement('ALTER TABLE account_types MODIFY account_id BIGINT UNSIGNED NOT NULL');

        // Add composite uniques per account
        Schema::table('account_types', function (Blueprint $table) {
            $table->unique(['account_id', 'code'], 'account_types_account_id_code_unique');
            $table->unique(['account_id', 'name'], 'account_types_account_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('account_types')) {
            return;
        }

        Schema::table('account_types', function (Blueprint $table) {
            $table->dropUnique('account_types_account_id_code_unique');
            $table->dropUnique('account_types_account_id_name_unique');
        });

        Schema::table('account_types', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });

        Schema::table('account_types', function (Blueprint $table) {
            $table->unique('code');
        });
    }
};
