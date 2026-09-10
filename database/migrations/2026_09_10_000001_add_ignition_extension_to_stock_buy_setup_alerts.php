<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->decimal('ignition_reference_price', 14, 4)->nullable()->after('spike_price_change_pct');
            $table->decimal('post_ignition_gain_pct', 10, 4)->nullable()->after('ignition_reference_price');
            $table->decimal('post_ignition_peak_gain_pct', 10, 4)->nullable()->after('post_ignition_gain_pct');
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'ignition_reference_price',
                'post_ignition_gain_pct',
                'post_ignition_peak_gain_pct',
            ]);
        });
    }
};
