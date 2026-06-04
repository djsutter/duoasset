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
        Schema::create('stage_transactions', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('num')->nullable();
            $table->foreignId('match_tx_id')->nullable();
            $table->dateTime('tx_at');
            $table->enum('tx_type', array_column(\App\Enums\TransactionType::cases(), 'value'));
            $table->string('description')->nullable();
            $table->string('address')->nullable();
            $table->enum('status', ['matched', 'unmatched', 'automatched', 'external', 'manual', 'ignored', 'confirmed', 'error']);
            $table->string('match_basis')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stage_transactions');
    }
};
