<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Make expires_at nullable so we can truly have "no expiration"
        // without the database forcing a default date.
        DB::statement('ALTER TABLE authorization_codes MODIFY expires_at TIMESTAMP NULL');
    }

    public function down(): void
    {
        // Revert to NOT NULL if needed (will set CURRENT_TIMESTAMP as default).
        DB::statement('ALTER TABLE authorization_codes MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
};
