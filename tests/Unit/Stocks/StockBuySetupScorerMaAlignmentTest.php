<?php

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\StockBuySetupScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * The ma_alignment score component is algorithm-aware (see
 * BuySetupAlgorithmRegistry::isReversalStyle()): trend-following algorithms
 * (heartbeat_consolidation_spike here) still require the full bullish
 * 50>150>200 stack, while the reversal-style floor_reversal_accumulation
 * algorithm rewards reclaiming the 50-day average instead — this was
 * previously "intentionally deferred" and is now un-deferred.
 */
test('trend-following setup types still require the full bullish MA stack for full points', function () {
    $scorer = new StockBuySetupScorer;

    $fullStack = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'ma_alignment' => '50>150>200, price>50',
    ]);
    $priceAbove50Only = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'ma_alignment' => 'mixed, price>50',
    ]);
    $fiftyOverTwoHundred = new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'ma_alignment' => '50>200, price<=50',
    ]);

    expect($scorer->breakdown($fullStack)['ma_alignment']['points'])->toBe(10)
        ->and($scorer->breakdown($priceAbove50Only)['ma_alignment']['points'])->toBe(0)
        ->and($scorer->breakdown($fiftyOverTwoHundred)['ma_alignment']['points'])->toBe(5);
});

test('floor reversal accumulation earns full MA-alignment points for reclaiming the 50-day average alone', function () {
    $scorer = new StockBuySetupScorer;

    $reclaimed50Only = new StockBuySetupAlert([
        'setup_type' => 'floor_reversal_accumulation',
        'ma_alignment' => 'mixed, price>50',
    ]);
    $fullBullishStack = new StockBuySetupAlert([
        'setup_type' => 'floor_reversal_accumulation',
        'ma_alignment' => '50>150>200, price>50',
    ]);
    $fiftyOverTwoHundredOnly = new StockBuySetupAlert([
        'setup_type' => 'floor_reversal_accumulation',
        'ma_alignment' => '50>200, price<=50',
    ]);
    $stillDeclining = new StockBuySetupAlert([
        'setup_type' => 'floor_reversal_accumulation',
        'ma_alignment' => 'mixed, price<=50',
    ]);

    expect($scorer->breakdown($reclaimed50Only)['ma_alignment']['points'])->toBe(10)
        ->and($scorer->breakdown($fullBullishStack)['ma_alignment']['points'])->toBe(10)
        ->and($scorer->breakdown($fiftyOverTwoHundredOnly)['ma_alignment']['points'])->toBe(5)
        ->and($scorer->breakdown($stillDeclining)['ma_alignment']['points'])->toBe(0);
});

test('reversal-style MA scoring only applies to setup types whose algorithm is reversal-style', function () {
    $scorer = new StockBuySetupScorer;

    // range_compression_breakout defaults to its own (trend-following)
    // algorithm key, so price>50 alone must NOT earn full points here.
    $alert = new StockBuySetupAlert([
        'setup_type' => 'range_compression_breakout',
        'ma_alignment' => 'mixed, price>50',
    ]);

    expect($scorer->breakdown($alert)['ma_alignment']['points'])->toBe(0);
});
