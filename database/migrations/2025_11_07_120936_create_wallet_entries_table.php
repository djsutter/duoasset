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
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->dateTime('transaction_at');
            $table->foreignId('transaction_id');
            $table->foreignId('wallet_id');
            $table->enum('entry_type', ['in', 'out', 'fee', 'sender-fee']);
            $table->decimal('amount', 36, 18)->nullable();
            $table->string('currency', 10)->nullable();
            $table->decimal('foreign_amount', 36, 18)->nullable();
            $table->string('foreign_currency', 10)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
    }
};
