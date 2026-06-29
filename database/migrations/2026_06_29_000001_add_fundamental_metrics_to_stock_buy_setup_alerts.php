<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->decimal('quarterly_eps_growth_pct', 10, 4)->nullable()->after('sales_acceleration');
            $table->decimal('quarterly_revenue_growth_pct', 10, 4)->nullable()->after('quarterly_eps_growth_pct');
            $table->decimal('annual_eps_growth_pct', 10, 4)->nullable()->after('quarterly_revenue_growth_pct');
            $table->decimal('roe_pct', 10, 4)->nullable()->after('annual_eps_growth_pct');
            $table->decimal('profit_margin_pct', 10, 4)->nullable()->after('roe_pct');
            $table->decimal('spike_relative_volume', 12, 4)->nullable()->after('profit_margin_pct');
            $table->json('eps_growth_sequence')->nullable()->after('spike_relative_volume');
            $table->json('revenue_growth_sequence')->nullable()->after('eps_growth_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'quarterly_eps_growth_pct',
                'quarterly_revenue_growth_pct',
                'annual_eps_growth_pct',
                'roe_pct',
                'profit_margin_pct',
                'spike_relative_volume',
                'eps_growth_sequence',
                'revenue_growth_sequence',
            ]);
        });
    }
};
