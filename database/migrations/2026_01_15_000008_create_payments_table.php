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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->enum('payment_type', ['receipt', 'payment']); // receipt = from client, payment = to supplier
            $table->unsignedBigInteger('invoice_id')->nullable(); // For client payments
            $table->unsignedBigInteger('bill_id')->nullable(); // For supplier payments
            $table->date('payment_date');
            $table->unsignedBigInteger('cash_account_id'); // Which cash account (1010, 1020, 1030)
            $table->decimal('amount', 15, 2);
            $table->string('payment_method')->default('cash'); // cash, check, bank_transfer
            $table->string('reference_number')->nullable(); // Check number, transaction reference
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable(); // Link to journal entry
            $table->timestamps();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->onDelete('restrict');

            $table->foreign('bill_id')
                ->references('id')
                ->on('bills')
                ->onDelete('restrict');

            $table->foreign('cash_account_id')
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
        Schema::dropIfExists('payments');
    }
};


