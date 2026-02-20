<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'void' to the invoices.status ENUM so voided invoices can be stored.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft', 'sent', 'paid', 'partial', 'overdue', 'void') DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optional: revert to original ENUM (only if no rows have status = 'void')
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft', 'sent', 'paid', 'partial', 'overdue') DEFAULT 'draft'");
    }
};
