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
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('journal_entry_id');
            $table->string('voided_by_type')->nullable()->after('voided_at');
            $table->unsignedBigInteger('voided_by_id')->nullable()->after('voided_by_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'voided_by_type', 'voided_by_id']);
        });
    }
};
