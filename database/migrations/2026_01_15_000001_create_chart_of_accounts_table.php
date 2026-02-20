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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 10)->unique();
            $table->string('account_name');
            $table->enum('account_type', [
                'ASSETS',
                'LIABILITIES',
                'EQUITY',
                'REVENUE',
                'COST_OF_SERVICES',
                'OPERATING_EXPENSES',
                'OTHER_INCOME_EXPENSES'
            ]);
            $table->enum('normal_balance', ['DR', 'CR']);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};


