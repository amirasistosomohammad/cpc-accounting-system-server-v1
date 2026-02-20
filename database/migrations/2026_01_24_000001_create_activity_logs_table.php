<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_type', 32); // 'admin' | 'personnel'
            $table->unsignedBigInteger('user_id');
            $table->string('user_name')->nullable();
            $table->string('action', 32); // 'login' | 'logout' | 'created' | 'updated' | 'deleted'
            $table->string('subject_type', 128)->nullable(); // e.g. App\Models\Client
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->unsignedBigInteger('authorization_code_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['user_type', 'user_id']);
            $table->index(['action']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
