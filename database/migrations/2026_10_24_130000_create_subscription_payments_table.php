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
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan'); // 'equities', 'crypto', 'full_subscription'
            $table->string('status')->default('pending'); // 'pending', 'completed', 'failed', 'cancelled'
            $table->decimal('amount', 10, 2);
            $table->decimal('credit_applied', 10, 2)->default(0.00);
            $table->decimal('amount_due', 10, 2);
            $table->string('currency', 10)->default('CAD');
            $table->string('provider')->default('swipe'); // 'swipe', 'stripe', 'internal_credit'
            $table->string('provider_session_id')->nullable()->index();
            $table->string('provider_payment_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
