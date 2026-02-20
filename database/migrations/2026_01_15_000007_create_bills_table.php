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
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number')->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('expense_account_id'); // Account from COA (e.g., 6030, 6210)
            $table->decimal('total_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('balance', 15, 2); // total_amount - paid_amount
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'received', 'paid', 'partial', 'overdue'])->default('draft');
            $table->unsignedBigInteger('journal_entry_id')->nullable(); // Link to journal entry
            $table->timestamps();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign('expense_account_id')
                ->references('id')
                ->on('chart_of_accounts')
                ->onDelete('restrict');

            $table->foreign('journal_entry_id')
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
        Schema::dropIfExists('bills');
    }
};


