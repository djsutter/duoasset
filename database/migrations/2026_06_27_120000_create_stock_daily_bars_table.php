<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_daily_bars', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32)->index();
            $table->date('bar_date');
            $table->decimal('open', 18, 6)->nullable();
            $table->decimal('high', 18, 6)->nullable();
            $table->decimal('low', 18, 6)->nullable();
            $table->decimal('close', 18, 6)->nullable();
            $table->decimal('adj_close', 18, 6)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'bar_date'], 'stock_daily_bars_symbol_date_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_daily_bars');
    }
};
