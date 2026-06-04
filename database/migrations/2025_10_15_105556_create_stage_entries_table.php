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
        Schema::create('stage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_transaction_id');
            $table->dateTime('tx_at');
            $table->enum('atomic_type', ['in', 'out', 'fee', 'sender-fee']);
            $table->foreignId('wallet_id')->nullable(); // nullable for now but not always.
            $table->string('amount', 64)->nullable();
            $table->string('currency', 12)->nullable();
            $table->string('foreign_amount', 64)->nullable();
            $table->string('foreign_currency', 12)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stage_entries');
    }
};
