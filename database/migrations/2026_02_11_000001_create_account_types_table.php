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
        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // e.g., 'ASSETS', 'LIABILITIES'
            $table->string('name'); // e.g., 'Assets', 'Liabilities'
            $table->string('normal_balance', 2)->default('DR'); // DR or CR (default normal balance)
            $table->string('color', 7)->nullable(); // Hex color for UI
            $table->string('icon', 50)->nullable(); // Icon name/class
            $table->integer('display_order')->default(0); // For ordering in UI
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_types');
    }
};
