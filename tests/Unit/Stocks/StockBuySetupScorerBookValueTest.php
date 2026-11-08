<?php

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('book value logarithmic curve matches expected reference points with default settings', function () {
    $scorer = new StockBuySetupScorer;

    // Reference table with default settings (W=10, E=1.25, A=0.90)
    $expected = [
        [0.50, 100.00, 10.00],
        [0.90, 100.00, 10.00],
        [1.00, 91.98, 9.20],
        [1.10, 84.73, 8.47],
        [1.25, 75.00, 7.50],
        [1.50, 61.12, 6.11],
        [2.00, 39.23, 3.92],
        [2.50, 22.25, 2.22],
        [3.00, 8.37, 0.84],
        [3.35, 0.00, 0.00],
        [4.00, 0.00, 0.00],
    ];

    foreach ($expected as [$pb, $expectedPct, $expectedPts]) {
        $fraction = $scorer->bookValueScoreFraction($pb, 'heartbeat_consolidation_spike');
        $points = $scorer->bookValuePoints($pb, 10, 'heartbeat_consolidation_spike');

        expect($fraction)->not->toBeNull()
            ->and(round($fraction * 100, 2))->toEqualWithDelta($expectedPct, 0.01)
            ->and(round((float) $points, 2))->toEqualWithDelta($expectedPts, 0.01);
    }

    // Exact anchor assertions
    expect($scorer->bookValueScoreFraction(0.90))->toBe(1.0)
        ->and($scorer->bookValuePoints(0.90, 10))->toBe(10)
        ->and($scorer->bookValueScoreFraction(1.25))->toBe(0.75)
        ->and($scorer->bookValuePoints(1.25, 10))->toBe(7.5)
        ->and($scorer->bookValueScoreFraction(3.35))->toBe(0.0)
        ->and($scorer->bookValuePoints(3.35, 10))->toBe(0)
        ->and($scorer->bookValueZeroScoreRatio())->toEqualWithDelta(3.3489, 0.001);
});

test('book value scores never exceed configured weight or fall below zero and are monotonically non-increasing', function () {
    $scorer = new StockBuySetupScorer;
    $weight = 15;

    $ratios = [0.05, 0.25, 0.50, 0.75, 0.89, 0.90, 0.95, 1.00, 1.10, 1.25, 1.50, 2.00, 2.50, 3.00, 3.34, 3.35, 4.00, 10.00, 100.00];
    $previousPoints = null;

    foreach ($ratios as $r) {
        $points = (float) $scorer->bookValuePoints($r, $weight);

        expect($points)->toBeGreaterThanOrEqual(0.0)
            ->and($points)->toBeLessThanOrEqual((float) $weight);

        if ($previousPoints !== null) {
            // Lower positive P/B ratios must never receive fewer points than higher ratios
            expect($previousPoints)->toBeGreaterThanOrEqual($points);
        }

        $previousPoints = $points;
    }
});

test('custom book value weight and valuation anchors automatically recalibrate the scoring curve', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();

    $config['setup_types']['heartbeat_consolidation_spike']['BOOK_VALUE_WEIGHT'] = 20;
    $config['setup_types']['heartbeat_consolidation_spike']['EXCEPTIONAL_BOOK_VALUE'] = 1.50;
    $config['setup_types']['heartbeat_consolidation_spike']['AMAZING_BOOK_VALUE'] = 0.75;
    $configService->saveConfig($config);

    $scorer = new StockBuySetupScorer;

    // P/B 0.75 = 20 points (100%)
    $alertAmazing = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'price_to_book_ratio' => 0.75,
    ]);
    $breakdownAmazing = $scorer->breakdown($alertAmazing);
    expect($breakdownAmazing['book_value']['max'])->toBe(20)
        ->and($breakdownAmazing['book_value']['points'])->toBe(20);

    // P/B 1.50 = 15 points (75%)
    $alertExceptional = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'price_to_book_ratio' => 1.50,
    ]);
    $breakdownExceptional = $scorer->breakdown($alertExceptional);
    expect($breakdownExceptional['book_value']['max'])->toBe(20)
        ->and($breakdownExceptional['book_value']['points'])->toBe(15);

    // Derived zero-score ratio = 0.75 * (1.50 / 0.75)^4 = 12.0
    expect($scorer->bookValueZeroScoreRatio('heartbeat_consolidation_spike'))->toEqualWithDelta(12.0, 0.0001)
        ->and($scorer->bookValuePoints(12.0, 20, 'heartbeat_consolidation_spike'))->toBe(0);
});

test('missing, zero, or negative book values and negative or non-finite P/B ratios are handled safely as N/A', function () {
    $scorer = new StockBuySetupScorer;
    $configService = app(BuySetupConfigService::class);

    // Disable all other components so only Book Value is active
    $config = $configService->getConfig();
    foreach ($config['setup_types']['heartbeat_consolidation_spike']['score_weights'] as $key => $meta) {
        $config['setup_types']['heartbeat_consolidation_spike']['score_weights'][$key]['enabled'] = ($key === 'book_value');
    }
    $configService->saveConfig($config);

    $invalidCases = [
        ['price_to_book_ratio' => null, 'book_value_per_share' => null],
        ['price' => 10.0, 'book_value_per_share' => 0.0],
        ['price' => 10.0, 'book_value_per_share' => -2.50],
        ['price' => 10.0, 'stockholders_equity' => 0.0, 'shares_outstanding' => 10_000_000],
        ['price' => 10.0, 'stockholders_equity' => -50_000_000.0, 'shares_outstanding' => 10_000_000],
        ['price_to_book_ratio' => -0.80],
        ['price_to_book_ratio' => 0.0],
    ];

    foreach ($invalidCases as $attributes) {
        $alert = new StockBuySetupAlert(array_merge([
            'setup_type' => 'heartbeat_consolidation_spike',
        ], $attributes));

        $breakdown = $scorer->breakdown($alert);
        $bv = $breakdown['book_value'];

        expect($bv['available'])->toBeFalse()
            ->and($bv['points'])->toBe(0)
            ->and($bv['max'])->toBe(0)
            ->and($bv['configured_max'])->toBe(10)
            ->and($bv['value'])->toBe('N/A (book value unavailable)');

        // Missing or negative book value must never produce a maximum score
        expect($scorer->scoreFromAlert($alert))->toBe(0);
    }

    // Non-finite floats passed directly to scorer methods
    expect($scorer->bookValueScoreFraction(INF))->toBeNull()
        ->and($scorer->bookValueScoreFraction(-INF))->toBeNull()
        ->and($scorer->bookValueScoreFraction(NAN))->toBeNull()
        ->and($scorer->bookValuePoints(INF, 10))->toBe(0)
        ->and($scorer->bookValuePoints(NAN, 10))->toBe(0);
});

test('valid expensive P/B earns 0 points while remaining in the normalization denominator, distinct from N/A', function () {
    $scorer = new StockBuySetupScorer;

    $expensiveAlert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_rarity_points' => 7,
        'base_duration_days' => 90,
        'range_compression_pct' => 8.0,
        'atr_contraction_ratio' => 0.55,
        'volume_dry_up_score' => 0.35,
        'distance_to_breakout_pct' => 1.0,
        'ma_alignment' => '50>150>200, price>50',
        'relative_strength_score' => 25.0,
        'earnings_acceleration' => 500.0,
        'sales_acceleration' => 5000.0,
        'operating_margin_expansion_bps' => 2000.0,
        'fcf_margin_expansion_bps' => 2000.0,
        'price_to_book_ratio' => 4.00, // Valid but expensive -> 0 / 10
    ]);

    $breakdown = $scorer->breakdown($expensiveAlert);
    expect($breakdown['book_value']['available'])->toBeTrue()
        ->and($breakdown['book_value']['points'])->toBe(0)
        ->and($breakdown['book_value']['max'])->toBe(10)
        ->and($breakdown['book_value']['value'])->toContain('0.00 / 10 (P/B 4.00)');

    // 130 earned out of 140 total active weight -> round(130/140 * 100) = 93 (not 100)
    expect($scorer->scoreFromAlert($expensiveAlert))->toBe(93);

    // When P/B is 1.10, breakdown value displays P/B Ratio, Component Score %, and Weighted Points
    $attractiveAlert = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'price_to_book_ratio' => 1.10,
    ]);
    $attractiveBreakdown = $scorer->breakdown($attractiveAlert);
    expect($attractiveBreakdown['book_value']['value'])
        ->toContain('P/B Ratio: 1.10')
        ->toContain('Component Score: 84.73%')
        ->toContain('Weighted Points: 8.47 / 10');
});

test('StockFundamentalsAnalyzer calculates book value per share and P/B ratio and guards currency mismatches', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = [
        ['date' => '2026-06-30', 'revenue' => 50_000_000, 'net_income' => 5_000_000, 'eps' => 0.50, 'reported_currency' => 'USD'],
    ];
    $balance = [
        ['date' => '2026-06-30', 'stockholders_equity' => 100_000_000, 'reported_currency' => 'USD'],
    ];

    // Price = $11.00, Shares = 10,000,000 -> Book Value/Share = $10.00, P/B = 1.10
    $metrics = $analyzer->analyze($income, $balance, [], 11.00, 10_000_000, 'USD');

    expect($metrics['stockholders_equity'])->toBe(100_000_000.0)
        ->and($metrics['book_value_per_share'])->toBe(10.0)
        ->and($metrics['price_to_book_ratio'])->toBe(1.1);

    // Currency mismatch (CAD price vs USD balance sheet) must return null for P/B and book_value_per_share
    $mismatch = $analyzer->analyze($income, $balance, [], 11.00, 10_000_000, 'CAD');
    expect($mismatch['book_value_per_share'])->toBeNull()
        ->and($mismatch['price_to_book_ratio'])->toBeNull();

    // Negative equity must return null for book_value_per_share and price_to_book_ratio
    $negativeBalance = [
        ['date' => '2026-06-30', 'stockholders_equity' => -25_000_000, 'reported_currency' => 'USD'],
    ];
    $negMetrics = $analyzer->analyze($income, $negativeBalance, [], 11.00, 10_000_000, 'USD');
    expect($negMetrics['stockholders_equity'])->toBe(-25_000_000.0)
        ->and($negMetrics['book_value_per_share'])->toBeNull()
        ->and($negMetrics['price_to_book_ratio'])->toBeNull();
});
