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
        Schema::create('day_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id');
            $table->date('date');
            $table->decimal('balance', 36, 18)->nullable();
            $table->string('currency', 12)->nullable();
            $table->timestamps();
            $table->unique(['wallet_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('day_balances');
    }
};
