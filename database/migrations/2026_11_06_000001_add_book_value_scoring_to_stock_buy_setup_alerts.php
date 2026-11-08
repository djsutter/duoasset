<?php

use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScorer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_buy_setup_alerts')) {
            Schema::table('stock_buy_setup_alerts', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_buy_setup_alerts', 'book_value_per_share')) {
                    $table->decimal('book_value_per_share', 16, 4)->nullable()->after('prior_ttm_fcf_margin');
                }
                if (! Schema::hasColumn('stock_buy_setup_alerts', 'price_to_book_ratio')) {
                    $table->decimal('price_to_book_ratio', 16, 4)->nullable()->after('book_value_per_share');
                }
                if (! Schema::hasColumn('stock_buy_setup_alerts', 'stockholders_equity')) {
                    $table->decimal('stockholders_equity', 20, 2)->nullable()->after('price_to_book_ratio');
                }
            });
        }

        try {
            app(BuySetupConfigService::class)->ensureBookValueConfigMigrated();
            app(StockBuySetupScorer::class)->recalculateHistoricalBaseline();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_buy_setup_alerts')) {
            return;
        }

        $columns = [
            'book_value_per_share',
            'price_to_book_ratio',
            'stockholders_equity',
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
    }
};
