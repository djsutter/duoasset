<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->decimal('fcf_margin_expansion_bps', 12, 4)->nullable()->after('prior_ttm_operating_margin');
            $table->decimal('current_ttm_fcf_margin', 10, 6)->nullable()->after('fcf_margin_expansion_bps');
            $table->decimal('prior_ttm_fcf_margin', 10, 6)->nullable()->after('current_ttm_fcf_margin');
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'fcf_margin_expansion_bps',
                'current_ttm_fcf_margin',
                'prior_ttm_fcf_margin',
            ]);
        });
    }
};
