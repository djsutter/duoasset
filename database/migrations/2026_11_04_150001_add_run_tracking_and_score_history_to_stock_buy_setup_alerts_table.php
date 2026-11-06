<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_buy_setup_alerts')) {
            return;
        }

        $needsRunIndex = ! Schema::hasColumn('stock_buy_setup_alerts', 'scan_run_id');
        $needsFirstRunIndex = ! Schema::hasColumn('stock_buy_setup_alerts', 'first_scan_run_id');

        Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'scan_run_id')) {
                $table->unsignedBigInteger('scan_run_id')->nullable()->after('source');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'first_scan_run_id')) {
                $table->unsignedBigInteger('first_scan_run_id')->nullable()->after('scan_run_id');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'previous_setup_score')) {
                $table->integer('previous_setup_score')->nullable()->after('setup_score');
            }
            if (! Schema::hasColumn('stock_buy_setup_alerts', 'score_delta')) {
                $table->integer('score_delta')->nullable()->after('previous_setup_score');
            }
        });

        if ($needsRunIndex) {
            try {
                Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                    $table->index(['scan_run_id', 'symbol'], 'stock_buy_setup_alerts_run_symbol_index');
                });
            } catch (\Throwable) {
            }
        }

        if ($needsFirstRunIndex) {
            try {
                Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                    $table->index('first_scan_run_id', 'stock_buy_setup_alerts_first_run_index');
                });
            } catch (\Throwable) {
            }
        }

        \App\Models\StockBuySetupAlert::clearSchemaCache();
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_buy_setup_alerts')) {
            return;
        }

        try {
            Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                $table->dropIndex('stock_buy_setup_alerts_run_symbol_index');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                $table->dropIndex('stock_buy_setup_alerts_first_run_index');
            });
        } catch (\Throwable) {
        }

        $columns = [
            'scan_run_id',
            'first_scan_run_id',
            'previous_setup_score',
            'score_delta',
        ];
        $existing = array_values(array_filter(
            $columns,
            fn (string $col) => Schema::hasColumn('stock_buy_setup_alerts', $col),
        ));

        if (! empty($existing)) {
            Schema::table('stock_buy_setup_alerts', function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }

        \App\Models\StockBuySetupAlert::clearSchemaCache();
    }
};
