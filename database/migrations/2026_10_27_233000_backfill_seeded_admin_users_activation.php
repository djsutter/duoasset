<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Backfill legacy and seeded admin accounts (name is 'Admin' or email is 'joe@7pro.ca')
        // that were created before the account activation requirement was introduced.
        DB::table('users')
            ->where(function ($query) {
                $query->where('name', 'Admin')
                    ->orWhere('email', 'dsutter@qualivera.com')
                    ->orWhere('email', 'joe@7pro.ca');
            })
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at' => now(),
                'activation_token' => null,
            ]);

        // Ensure any activation token on these seeded accounts is cleared
        DB::table('users')
            ->where(function ($query) {
                $query->where('name', 'Admin')
                    ->orWhere('email', 'dsutter@qualivera.com')
                    ->orWhere('email', 'joe@7pro.ca');
            })
            ->whereNotNull('activation_token')
            ->update([
                'activation_token' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only migration backfill. No rollback of verification timestamp.
    }
};
