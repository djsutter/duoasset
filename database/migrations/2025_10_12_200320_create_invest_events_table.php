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
        Schema::create('invest_events', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('wallet_id');
            $table->decimal('prev_balance', 36, 18)->nullable();
            $table->decimal('new_balance', 36, 18)->nullable();
            $table->decimal('change', 36, 18)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invest_events');
    }
};
