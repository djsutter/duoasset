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
        // Safeguard: protect permanent stock daily bars from accidental destruction during rollback
        if (Schema::hasTable('stock_daily_bars') && \Illuminate\Support\Facades\DB::table('stock_daily_bars')->exists()) {
            if (! env('ALLOW_DROP_MARKET_DATA', false) && ! config('market_data.allow_drop_tables', false)) {
                throw new \RuntimeException(
                    'Migration rollback halted: permanent market data table [stock_daily_bars] contains accumulated historical data. Set ALLOW_DROP_MARKET_DATA=true to bypass.'
                );
            }
        }

        Schema::dropIfExists('stock_daily_bars');
    }
};
