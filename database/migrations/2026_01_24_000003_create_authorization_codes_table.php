<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('admin_type', 32)->default('admin');
            $table->unsignedBigInteger('admin_id');
            $table->string('for_action', 64); // e.g. 'delete_client', 'delete_invoice'
            $table->string('subject_type', 128)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('used_by_type')->nullable(); // 1=admin, 2=personnel or store type
            $table->unsignedBigInteger('used_by_id')->nullable();
            $table->timestamps();

            $table->index(['code', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_codes');
    }
};
