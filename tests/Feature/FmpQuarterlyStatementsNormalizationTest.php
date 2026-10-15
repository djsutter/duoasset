<?php

use App\Services\MarketData\FmpMarketDataProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('keeps fiscal metadata and filing dates for restatement-aware comparisons', function () {
    Cache::flush();
    Http::fake(['*income-statement*' => Http::response([[
        'date' => '2025-03-31', 'fiscalYear' => 2025, 'period' => 'Q1',
        'fillingDate' => '2026-10-01', 'revenue' => 50_900_000,
        'reportedCurrency' => 'USD',
    ]])]);
    $provider = new FmpMarketDataProvider('https://fmp.test/stable', 'test');
    $rows = $provider->quarterlyIncomeStatements('NBIS');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['filing_date'])->toBe('2026-10-01')
        ->and($rows[0]['revenue'])->toBe(50_900_000.0);
});

it('uses sign-safe EPS surprise for loss-making estimates rather than a vendor percentage', function () {
    Http::fake(['*earnings-calendar*' => Http::response([[
        'symbol' => 'LOSS', 'date' => '2026-10-15',
        'epsEstimated' => -1.0, 'epsActual' => -0.5, 'surprisePercentage' => -50,
    ]])]);
    $provider = new FmpMarketDataProvider('https://fmp.test/stable', 'test');
    $rows = $provider->earningsSurprises(now(), now());
    expect($rows[0]['eps_surprise_percent'])->toBe(50.0)
        ->and($rows[0]['eps_surprise'])->toBe(0.5);
});

it('does not use provider EPS percent for a near-zero estimate', function () {
    Http::fake(['*earnings-calendar*' => Http::response([[
        'symbol' => 'TINY', 'date' => '2026-10-15',
        'epsEstimated' => 0.005, 'epsActual' => 0.5, 'surprisePercentage' => 9900,
    ]])]);
    $provider = new FmpMarketDataProvider('https://fmp.test/stable', 'test');
    expect($provider->earningsSurprises(now(), now())[0]['eps_surprise_percent'])->toBeNull();
});
