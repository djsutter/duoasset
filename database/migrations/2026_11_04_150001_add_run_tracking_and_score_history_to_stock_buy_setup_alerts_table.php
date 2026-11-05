<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('scan_run_id')->nullable()->after('source');
            $table->unsignedBigInteger('first_scan_run_id')->nullable()->after('scan_run_id');
            $table->integer('previous_setup_score')->nullable()->after('setup_score');
            $table->integer('score_delta')->nullable()->after('previous_setup_score');

            $table->index(['scan_run_id', 'symbol'], 'stock_buy_setup_alerts_run_symbol_index');
            $table->index('first_scan_run_id', 'stock_buy_setup_alerts_first_run_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            $table->dropIndex('stock_buy_setup_alerts_run_symbol_index');
            $table->dropIndex('stock_buy_setup_alerts_first_run_index');
            $table->dropColumn([
                'scan_run_id',
                'first_scan_run_id',
                'previous_setup_score',
                'score_delta',
            ]);
        });
    }
};
