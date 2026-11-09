<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->index(['symbol', 'setup_type', 'spike_date', 'id'], 'sb_alerts_sym_type_spike_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropIndex('sb_alerts_sym_type_spike_id_idx');
        });
    }
};
