<?php

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the production incident where the scanner job
 * failed to persist alerts with:
 *
 *   SQLSTATE[22003]: Numeric value out of range: 1264
 *   Out of range value for column 'roe_pct'
 *   Out of range value for column 'profit_margin_pct'
 *
 * caused by a near-zero (but positive) revenue/equity denominator
 * producing a multi-million-percent ratio that doesn't fit the
 * decimal(10,4) columns on MySQL. The fix neutralizes those ratios in
 * StockFundamentalsAnalyzer (see StockFundamentalsAnalyzerProfitabilityTest
 * for the exact-boundary math), and this test drives the guarded output
 * through the full create()/reload path to confirm nothing downstream
 * (fillable, casts, DB round-trip) breaks the null-out behavior.
 */
test('an alert built from a near-zero revenue/equity denominator persists and reloads roe_pct/profit_margin_pct as null', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Mirrors the reported LYEL-style scenario: tiny revenue/equity relative
    // to a large net loss, which previously produced a -1,118,875%
    // profit_margin_pct and an even larger out-of-range roe_pct ratio.
    $incomeRows = [
        ['date' => '2024-01-01', 'revenue' => 1000, 'net_income' => -11188750, 'eps' => null],
        ['date' => '2024-04-01', 'revenue' => 1000, 'net_income' => -11188750, 'eps' => null],
        ['date' => '2024-07-01', 'revenue' => 1000, 'net_income' => -11188750, 'eps' => null],
        ['date' => '2024-10-01', 'revenue' => 1000, 'net_income' => -11188750, 'eps' => null],
    ];
    $balanceRows = [
        ['date' => '2024-10-01', 'stockholders_equity' => 1000],
    ];

    $metrics = $analyzer->analyze($incomeRows, $balanceRows);

    // Sanity check: the analyzer itself must have already neutralized the
    // out-of-range ratios before they ever reach the database layer.
    expect($metrics['roe_pct'])->toBeNull()
        ->and($metrics['profit_margin_pct'])->toBeNull();

    $save = function () use ($metrics) {
        StockBuySetupAlert::create([
            'source' => 'fmp',
            'symbol' => 'LYEL',
            'setup_type' => 'heartbeat_consolidation_spike',
            'setup_score' => 31,
            'raw_setup_score' => 31,
            'spike_date' => '2026-06-26',
            'detected_at' => now(),
            'status' => 'new',
            'roe_pct' => $metrics['roe_pct'],
            'profit_margin_pct' => $metrics['profit_margin_pct'],
        ]);
    };

    expect($save)->not->toThrow(Throwable::class);

    $alert = StockBuySetupAlert::where('symbol', 'LYEL')->first();
    expect($alert)->not->toBeNull()
        ->and($alert->roe_pct)->toBeNull()
        ->and($alert->profit_margin_pct)->toBeNull();
});
