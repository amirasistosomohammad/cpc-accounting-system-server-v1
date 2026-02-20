<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_type', 32); // 'admin' | 'personnel'
            $table->unsignedBigInteger('user_id');
            $table->string('user_name')->nullable();
            $table->date('log_date');
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->string('source', 32)->default('manual'); // 'manual' | 'system'
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['user_type', 'user_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_logs');
    }
};
