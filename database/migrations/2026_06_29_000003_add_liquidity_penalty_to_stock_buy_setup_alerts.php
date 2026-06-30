<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'raw_setup_score')) {
                $table->unsignedInteger('raw_setup_score')->default(0)->after('setup_score');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'price')) {
                $table->decimal('price', 14, 4)->nullable()->after('market_cap_category');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'shares_outstanding')) {
                $table->unsignedBigInteger('shares_outstanding')->nullable()->after('price');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'float_shares')) {
                $table->unsignedBigInteger('float_shares')->nullable()->after('shares_outstanding');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'free_float')) {
                $table->decimal('free_float', 10, 4)->nullable()->after('float_shares');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'avg_daily_volume')) {
                $table->unsignedBigInteger('avg_daily_volume')->nullable()->after('free_float');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'liquidity_turnover_pct')) {
                $table->decimal('liquidity_turnover_pct', 12, 6)->nullable()->after('avg_daily_volume');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'liquidity_penalty_pct')) {
                $table->decimal('liquidity_penalty_pct', 8, 4)->default(0)->after('liquidity_turnover_pct');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'liquidity_penalty_points')) {
                $table->unsignedInteger('liquidity_penalty_points')->default(0)->after('liquidity_penalty_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            foreach ([
                'raw_setup_score',
                'price',
                'shares_outstanding',
                'float_shares',
                'free_float',
                'avg_daily_volume',
                'liquidity_turnover_pct',
                'liquidity_penalty_pct',
                'liquidity_penalty_points',
            ] as $column) {
                if (Schema::hasColumn('stock_buy_setup_alerts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
