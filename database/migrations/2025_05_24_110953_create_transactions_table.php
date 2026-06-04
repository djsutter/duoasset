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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->datetime('transaction_at');
            $table->enum('tx_type', array_column(\App\Enums\TransactionType::cases(), 'value'));
            $table->string('description');
            $table->string('address')->nullable();
            $table->boolean('is_income')->default(false);
            $table->enum('valuation_status', ['pending', 'processing', 'done', 'failed'])->default('pending')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
