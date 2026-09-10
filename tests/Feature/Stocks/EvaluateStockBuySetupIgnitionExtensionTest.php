<?php

use App\Jobs\EvaluateStockBuySetup;
use App\Models\StockBuySetupAlert;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupLiquidityPenalty;
use App\Services\Stocks\StockBuySetupScanner;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\StockProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('EvaluateStockBuySetup calculates and persists ignition extension metrics', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus'] = [
        'bonus_points' => 20,
        'color' => 'yellow',
        'min_base_days' => 45,
        'min_volume_dry_up_pct' => 10.0,
        'price_led_min_relative_volume' => 2.0,
        'price_led_min_price_change_pct' => 10.0,
        'volume_led_min_relative_volume' => 3.0,
        'volume_led_min_price_change_pct' => 8.0,
        'full_bonus_max_post_gain_pct' => 15.0,
        'zero_bonus_post_gain_pct' => 40.0,
    ];
    $configService->saveConfig($config);

    // Build 260 trading days: 250 base days, 1 spike day, 9 subsequent bars
    $baseDate = CarbonImmutable::parse('2025-09-01');
    $bars = [];
    for ($i = 0; $i < 250; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 10.0,
            'high' => 10.2,
            'low' => 9.8,
            'close' => 10.0,
            'adj_close' => 10.0,
            'volume' => 100000,
        ];
    }

    // Day 250: Spike day (+15% price gain on 5x volume)
    $spikeDate = $baseDate->addDays(250)->toDateString();
    $bars[] = [
        'date' => $spikeDate,
        'open' => 10.0,
        'high' => 11.5,
        'low' => 9.9,
        'close' => 11.5,
        'adj_close' => 11.5,
        'volume' => 500000,
    ];

    // Days 251-258: Post-ignition bars moving up to 13.80 (+20% from 11.50) with peak at 14.375 (+25% from 11.50)
    for ($i = 1; $i <= 4; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays(250 + $i)->toDateString(),
            'open' => 11.5,
            'high' => 12.5,
            'low' => 11.4,
            'close' => 12.5,
            'adj_close' => 12.5,
            'volume' => 150000,
        ];
    }
    // Peak bar: close = 14.375 (+25% from 11.50)
    $bars[] = [
        'date' => $baseDate->addDays(255)->toDateString(),
        'open' => 12.5,
        'high' => 14.5,
        'low' => 12.4,
        'close' => 14.375,
        'adj_close' => 14.375,
        'volume' => 150000,
    ];
    // Pullback to 13.80 (+20% from 11.50)
    for ($i = 6; $i <= 8; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays(250 + $i)->toDateString(),
            'open' => 14.0,
            'high' => 14.2,
            'low' => 13.5,
            'close' => 13.80,
            'adj_close' => 13.80,
            'volume' => 120000,
        ];
    }

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('companyProfile')->andReturn([
        'company_name' => 'Fresh Ignition Corp',
        'exchange' => 'NASDAQ',
        'price' => 13.80,
        'shares_outstanding' => 50000000,
        'float_shares' => 40000000,
        'free_float' => 80.0,
        'market_cap' => 690000000,
    ]);
    $mockProvider->shouldReceive('historicalDailyBars')->andReturn($bars);
    $mockProvider->shouldReceive('quarterlyIncomeStatements')->andReturn([]);
    $mockProvider->shouldReceive('quarterlyBalanceSheets')->andReturn([]);
    $mockProvider->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

    $job = new EvaluateStockBuySetup('IGNT', 'Fresh Ignition Corp', 'NASDAQ', 690000000, 13.80, 50000000, 40000000, 80.0);
    $result = $job->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
    );

    expect($result['status'])->not->toBe('rejected');

    $alert = StockBuySetupAlert::where('symbol', 'IGNT')->first();
    expect($alert)->not->toBeNull()
        ->and((float) $alert->ignition_reference_price)->toEqualWithDelta(11.5, 0.01)
        ->and((float) $alert->post_ignition_gain_pct)->toEqualWithDelta(20.0, 0.01)
        ->and((float) $alert->post_ignition_peak_gain_pct)->toEqualWithDelta(25.0, 0.01);
});
