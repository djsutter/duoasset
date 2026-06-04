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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('currency', 10);
            $table->foreignId('platform_id')->nullable();
            $table->decimal('opening_balance', 36, 18)->nullable();
            $table->decimal('balance', 36, 18)->nullable();
            $table->enum('type', ['wallet', 'external', 'fee'])->default('wallet');
            $table->boolean('is_active')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
