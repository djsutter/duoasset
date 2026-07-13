<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->unsignedSmallInteger('spike_age_bars')->nullable()->after('days_since_previous_comparable_spike');
            $table->unsignedTinyInteger('spike_rarity_points')->default(0)->after('spike_age_bars');
            $table->string('spike_rarity_description')->nullable()->after('spike_rarity_points');
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'spike_age_bars',
                'spike_rarity_points',
                'spike_rarity_description',
            ]);
        });
    }
};
