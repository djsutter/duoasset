<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->decimal('operating_margin_expansion_bps', 12, 4)->nullable()->after('revenue_growth_sequence');
            $table->decimal('current_ttm_operating_margin', 10, 6)->nullable()->after('operating_margin_expansion_bps');
            $table->decimal('prior_ttm_operating_margin', 10, 6)->nullable()->after('current_ttm_operating_margin');
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'operating_margin_expansion_bps',
                'current_ttm_operating_margin',
                'prior_ttm_operating_margin',
            ]);
        });
    }
};
