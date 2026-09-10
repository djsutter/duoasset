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

test('EvaluateStockBuySetup calculates, decays, scores, and persists ignition extension metrics', function () {
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
            'volume' => $i >= 220 ? 50000 : 100000,
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

    // Days 251-254: Post-ignition bars moving up to 12.50
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
    // Peak bar: close = 14.375 (+25% from reference 11.50)
    $bars[] = [
        'date' => $baseDate->addDays(255)->toDateString(),
        'open' => 12.5,
        'high' => 14.5,
        'low' => 12.4,
        'close' => 14.375,
        'adj_close' => 14.375,
        'volume' => 150000,
    ];
    // Pullback to 13.80 (+20% from reference 11.50)
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
    $mockProvider->shouldReceive('profile')->with('IGNT')->andReturn([
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

    expect($result['status'])->toBe('matched');

    $alert = StockBuySetupAlert::where('symbol', 'IGNT')->first();
    expect($alert)->not->toBeNull()
        ->and((float) $alert->ignition_reference_price)->toEqualWithDelta(11.5, 0.01)
        ->and((float) $alert->post_ignition_gain_pct)->toEqualWithDelta(20.0, 0.01)
        ->and((float) $alert->post_ignition_peak_gain_pct)->toEqualWithDelta(25.0, 0.01);

    // Assert that the scorer calculates a decayed bonus of 12 / 20 at 25% peak gain
    // Multiplier = (40 - 25) / (40 - 15) = 15/25 = 0.60; 20 * 0.60 = 12 points
    $scorer = app(StockBuySetupScorer::class);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');
    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toEqualWithDelta(0.60, 0.01)
        ->and($bonus['points'])->toBe(12)
        ->and($bonus['max'])->toBe(20);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['points'])->toBe(12)
        ->and($entry['max'])->toBe(20)
        ->and($entry['value'])->toContain('Post-ignition: +20.0% current | +25.0% peak | freshness 60%');

    // Setup score must incorporate the 12 ignition bonus points
    expect($alert->setup_score)->toBe(min(100, $alert->raw_setup_score - $alert->liquidity_penalty_points + 12));
});

test('EvaluateStockBuySetup preserves peak post-ignition gain across rescans during pullback', function () {
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

    // Build 260 trading days: 250 base days at $9.00, 1 spike day to $10.00 (+11.1%), 5 subsequent bars
    $baseDate = CarbonImmutable::parse('2025-09-01');
    $bars = [];
    for ($i = 0; $i < 250; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 9.0,
            'high' => 9.2,
            'low' => 8.8,
            'close' => 9.0,
            'adj_close' => 9.0,
            'volume' => $i >= 220 ? 50000 : 100000,
        ];
    }

    // Day 250: Spike day (+11.1% price gain to $10.00 reference on 5x volume)
    $spikeDate = $baseDate->addDays(250)->toDateString();
    $bars[] = [
        'date' => $spikeDate,
        'open' => 9.0,
        'high' => 10.0,
        'low' => 8.9,
        'close' => 10.0,
        'adj_close' => 10.0,
        'volume' => 500000,
    ];

    // Subsequent bars completed closing peak = $13.20 (+32% from 10.00)
    for ($i = 1; $i <= 5; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays(250 + $i)->toDateString(),
            'open' => 10.0,
            'high' => 13.5,
            'low' => 10.0,
            'close' => 13.20,
            'adj_close' => 13.20,
            'volume' => 150000,
        ];
    }

    // SCAN 1: Intraday live quote surges to $14.50 (+45% peak excursion >= 40%)
    $mockProvider1 = Mockery::mock(MarketDataProvider::class);
    $mockProvider1->shouldReceive('profile')->with('PEAK')->andReturn([
        'company_name' => 'Peak Permanence Inc',
        'exchange' => 'NASDAQ',
        'price' => 14.50,
        'shares_outstanding' => 50000000,
        'float_shares' => 40000000,
        'free_float' => 80.0,
        'market_cap' => 725000000,
    ]);
    $mockProvider1->shouldReceive('historicalDailyBars')->andReturn($bars);
    $mockProvider1->shouldReceive('quarterlyIncomeStatements')->andReturn([]);
    $mockProvider1->shouldReceive('quarterlyBalanceSheets')->andReturn([]);
    $mockProvider1->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

    $job1 = new EvaluateStockBuySetup('PEAK', 'Peak Permanence Inc', 'NASDAQ', 725000000, 14.50, 50000000, 40000000, 80.0);
    $job1->handle(
        $mockProvider1,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
    );

    $alert1 = StockBuySetupAlert::where('symbol', 'PEAK')->first();
    expect($alert1)->not->toBeNull()
        ->and((float) $alert1->post_ignition_peak_gain_pct)->toEqualWithDelta(45.0, 0.01);

    $scorer = app(StockBuySetupScorer::class);
    $bonus1 = $scorer->ignitionBonus($alert1, 'heartbeat_consolidation_spike');
    expect($bonus1['points'])->toBe(0)
        ->and($bonus1['freshness_eligible'])->toBeFalse();

    // SCAN 2: Stock pulls back to $12.80 (+28% from 10.00). Historical completed closes peak is still $13.20 (+32%).
    // The previous 45% peak must NOT be overwritten with 32%, and the bonus must NOT reactivate.
    $mockProvider2 = Mockery::mock(MarketDataProvider::class);
    $mockProvider2->shouldReceive('profile')->with('PEAK')->andReturn([
        'company_name' => 'Peak Permanence Inc',
        'exchange' => 'NASDAQ',
        'price' => 12.80,
        'shares_outstanding' => 50000000,
        'float_shares' => 40000000,
        'free_float' => 80.0,
        'market_cap' => 640000000,
    ]);
    $mockProvider2->shouldReceive('historicalDailyBars')->andReturn([]);
    $mockProvider2->shouldReceive('quarterlyIncomeStatements')->andReturn([]);
    $mockProvider2->shouldReceive('quarterlyBalanceSheets')->andReturn([]);
    $mockProvider2->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

    $job2 = new EvaluateStockBuySetup('PEAK', 'Peak Permanence Inc', 'NASDAQ', 640000000, 12.80, 50000000, 40000000, 80.0);
    $res2 = $job2->handle(
        $mockProvider2,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
    );
    expect($res2['status'])->toBe('matched');

    $allAlerts = StockBuySetupAlert::where('symbol', 'PEAK')->get();
    expect($allAlerts)->toHaveCount(1);
    $alert2 = $allAlerts->first();
    expect($alert2)->not->toBeNull()
        ->and((float) $alert2->post_ignition_gain_pct)->toEqualWithDelta(28.0, 0.01)
        ->and((float) $alert2->post_ignition_peak_gain_pct)->toEqualWithDelta(45.0, 0.01);

    $bonus2 = $scorer->ignitionBonus($alert2, 'heartbeat_consolidation_spike');
    expect($bonus2['points'])->toBe(0)
        ->and($bonus2['freshness_eligible'])->toBeFalse()
        ->and($alert2->setup_score)->toBe($alert2->raw_setup_score - $alert2->liquidity_penalty_points);

    $entry2 = $scorer->ignitionBonusBreakdownEntry($alert2, 'heartbeat_consolidation_spike');
    expect($entry2['points'])->toBe(0)
        ->and($entry2['value'])->toContain('bonus expired');
});
