<?php

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it normalizes setup score to 100 even when components are disabled or weights change', function () {
    $scorer = new StockBuySetupScorer;
    $configService = app(BuySetupConfigService::class);

    $alert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_rarity_points' => 7, // full points on spike rarity
        'base_duration_days' => 90, // full points on base duration
        'range_compression_pct' => 8.0, // full points on range compression
        'atr_contraction_ratio' => 0.55, // full points on atr contraction
        'volume_dry_up_score' => 0.35, // full points on volume dry-up
        'distance_to_breakout_pct' => 1.0, // full points on breakout distance
        'ma_alignment' => '50>150>200, price>50', // full points on MA
        'relative_strength_score' => 25.0, // full points on relative strength
        'earnings_acceleration' => 75.0,
        'sales_acceleration' => 3000.0,
    ]);

    // With all default weights enabled (total = 100), max score is 100
    $defaultScore = $scorer->scoreFromAlert($alert);
    expect($defaultScore)->toBe(100);

    // Disable BASE_DURATION_WEIGHT as mentioned in user issue
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['score_weights']['base_duration']['enabled'] = false;
    $configService->saveConfig($config);

    $breakdown = $scorer->breakdown($alert);
    expect($breakdown['base_duration']['points'])->toBe(0)
        ->and($breakdown['base_duration']['max'])->toBe(0);

    // Remaining enabled weights sum to 90. Since all other components scored full points, 90/90 normalizes to 100!
    $adjustedScore = $scorer->scoreFromAlert($alert);
    expect($adjustedScore)->toBe(100);
});

test('it properly scales spike rarity points up to 25 points based on configured weight', function () {
    $scorer = new StockBuySetupScorer;
    $alert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_rarity_points' => 7,
    ]);

    $breakdown = $scorer->breakdown($alert);
    expect($breakdown['spike_rarity']['max'])->toBe(25)
        ->and($breakdown['spike_rarity']['points'])->toBe(25);
});

test('operating margin expansion is disabled by default and does not affect the setup score', function () {
    $scorer = new StockBuySetupScorer;
    $alert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'operating_margin_expansion_bps' => 1300.0,
    ]);

    $breakdown = $scorer->breakdown($alert);

    expect($breakdown['operating_margin_expansion']['max'])->toBe(0)
        ->and($breakdown['operating_margin_expansion']['points'])->toBe(0);
});

test('enabling operating margin expansion interpolates points from configured thresholds', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['score_weights']['operating_margin_expansion'] = [
        'weight' => 20,
        'enabled' => true,
    ];
    $configService->saveConfig($config);

    $scorer = new StockBuySetupScorer;

    // +1300 bps with default thresholds (25=250,50=500,75=1000,100=1500)
    // interpolates to 90 points out of 100 -> 20 * 0.90 = 18 points.
    $alert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'operating_margin_expansion_bps' => 1300.0,
    ]);

    $breakdown = $scorer->breakdown($alert);

    expect($breakdown['operating_margin_expansion']['max'])->toBe(20)
        ->and($breakdown['operating_margin_expansion']['points'])->toBe(18);
});

test('operating margin expansion earns zero points when bps is null even if enabled', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['score_weights']['operating_margin_expansion'] = [
        'weight' => 20,
        'enabled' => true,
    ];
    $configService->saveConfig($config);

    $scorer = new StockBuySetupScorer;
    $alert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'operating_margin_expansion_bps' => null,
    ]);

    $breakdown = $scorer->breakdown($alert);

    expect($breakdown['operating_margin_expansion']['points'])->toBe(0);
});

test('custom operating margin expansion thresholds change the earned points', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['score_weights']['operating_margin_expansion'] = [
        'weight' => 100,
        'enabled' => true,
    ];
    $config['setup_types']['heartbeat_consolidation_spike']['operating_margin_expansion_thresholds'] = [
        'threshold_25' => 100,
        'threshold_50' => 200,
        'threshold_75' => 400,
        'threshold_100' => 800,
    ];
    $configService->saveConfig($config);

    $scorer = new StockBuySetupScorer;
    $alert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'operating_margin_expansion_bps' => 800.0,
    ]);

    $breakdown = $scorer->breakdown($alert);

    expect($breakdown['operating_margin_expansion']['points'])->toBe(100);
});
