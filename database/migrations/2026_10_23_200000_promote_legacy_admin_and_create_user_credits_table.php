<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add credit_balance column to users table
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'credit_balance')) {
                $table->decimal('credit_balance', 12, 2)->default(0.00)->after('view_mode');
            }
        });

        // Promote legacy seeded admin accounts (name is 'Admin' or email is 'demo@crypto.x')
        DB::table('users')
            ->where('name', 'Admin')
            ->orWhere('email', 'demo@crypto.x')
            ->update([
                'role' => UserRole::ADMINISTRATOR->value,
                'view_mode' => 'stocks',
            ]);

        // Create user_credits table for tracking dollar credit grants and adjustments
        if (! Schema::hasTable('user_credits')) {
            Schema::create('user_credits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->string('type')->default('credit');
                $table->string('description')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_credits');

        if (Schema::hasColumn('users', 'credit_balance')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('credit_balance');
            });
        }
    }
};
