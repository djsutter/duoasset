<?php

use App\Services\Stocks\StockFundamentalsAnalyzer;

/**
 * Builds 8 quarterly income statement rows (newest first) with the given
 * per-quarter revenue/operatingIncome pairs. $quarters[0] is the newest
 * (Q0), $quarters[7] is the oldest required quarter (Q7).
 *
 * @param  array<int, array{0: float, 1: float|null}>  $quarters
 * @return array<int, array<string, mixed>>
 */
function omeRows(array $quarters, array $overrides = []): array
{
    $rows = [];
    $date = new DateTimeImmutable('2025-06-30');
    foreach ($quarters as $i => [$revenue, $operatingIncome]) {
        $quarterDate = $date->modify('-'.($i * 3).' months');
        $row = [
            'date' => $quarterDate->format('Y-m-d'),
            'revenue' => $revenue,
            'operating_income' => $operatingIncome,
            'fiscal_year' => (int) $quarterDate->format('Y'),
            'period' => 'Q'.(int) ceil((int) $quarterDate->format('n') / 3),
            'reported_currency' => 'USD',
        ];
        $rows[] = array_merge($row, $overrides[$i] ?? []);
    }

    return $rows;
}

test('it calculates a normal profitable company margin expansion', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Current TTM: revenue 1500, operating income 225 -> margin 15%
    // Prior TTM: revenue 1000, operating income 100 -> margin 10%
    // Expansion = +500 bps
    $rows = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25], // current TTM
        [250, 25], [250, 25], [250, 25], [250, 25], // prior TTM
    ]);

    $result = $analyzer->operatingMarginExpansion($rows);

    expect($result['current_ttm_revenue'])->toBe(1500.0)
        ->and($result['current_ttm_operating_income'])->toBe(225.0)
        ->and($result['current_ttm_operating_margin'])->toBe(0.15)
        ->and($result['prior_ttm_revenue'])->toBe(1000.0)
        ->and($result['prior_ttm_operating_income'])->toBe(100.0)
        ->and($result['prior_ttm_operating_margin'])->toBe(0.1)
        ->and($result['operating_margin_expansion_bps'])->toBe(500.0);
});

test('it scores negative-to-less-negative margins as a strong positive expansion', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Current TTM: revenue 1500, operating income -75 -> margin -5%
    // Prior TTM: revenue 1000, operating income -200 -> margin -20%
    // Expansion = +1500 bps
    $rows = omeRows([
        [375, -18.75], [375, -18.75], [375, -18.75], [375, -18.75],
        [250, -50], [250, -50], [250, -50], [250, -50],
    ]);

    $result = $analyzer->operatingMarginExpansion($rows);

    expect($result['current_ttm_operating_margin'])->toBe(-0.05)
        ->and($result['prior_ttm_operating_margin'])->toBe(-0.2)
        ->and($result['operating_margin_expansion_bps'])->toBe(1500.0);
});

test('it reports margin contraction as a negative bps value', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Current TTM margin 15%, prior TTM margin 20% -> -500 bps
    $rows = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [250, 50], [250, 50], [250, 50], [250, 50],
    ]);

    $result = $analyzer->operatingMarginExpansion($rows);

    expect($result['operating_margin_expansion_bps'])->toBe(-500.0);
});

test('it returns null results when fewer than eight valid quarters are available', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $rows = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [250, 25], [250, 25], [250, 25],
    ]);

    $result = $analyzer->operatingMarginExpansion($rows);

    expect($result['operating_margin_expansion_bps'])->toBeNull()
        ->and($result['current_ttm_operating_margin'])->toBeNull();
});

test('it treats null revenue as missing data', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $rows = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [250, 25], [250, 25], [250, 25], [250, 25],
    ]);
    $rows[7]['revenue'] = null;

    expect($analyzer->operatingMarginExpansion($rows)['operating_margin_expansion_bps'])->toBeNull();
});

test('it returns null when a rolling ttm revenue total is zero', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $rows = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [0, 25], [0, 25], [0, 25], [0, 25],
    ]);

    expect($analyzer->operatingMarginExpansion($rows)['operating_margin_expansion_bps'])->toBeNull();
});

test('it treats null operating income as missing data but allows negative operating income', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $rowsWithNull = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [250, 25], [250, 25], [250, 25], [250, 25],
    ]);
    $rowsWithNull[3]['operating_income'] = null;
    expect((new StockFundamentalsAnalyzer)->operatingMarginExpansion($rowsWithNull)['operating_margin_expansion_bps'])->toBeNull();

    $rowsWithNegative = omeRows([
        [375, -56.25], [375, -56.25], [375, -56.25], [375, -56.25],
        [250, 25], [250, 25], [250, 25], [250, 25],
    ]);
    expect($analyzer->operatingMarginExpansion($rowsWithNegative)['operating_margin_expansion_bps'])->not->toBeNull();
});

test('it deduplicates repeated fiscal quarters instead of double counting them', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $rows = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [250, 25], [250, 25], [250, 25], [250, 25],
    ]);

    // Duplicate Q0 (index 0) with identical fiscal_year/period.
    $duplicate = $rows[0];
    array_unshift($rows, $duplicate);

    $result = $analyzer->operatingMarginExpansion($rows);

    // Still exactly 500 bps expansion — duplicate must not be double-counted.
    expect($result['current_ttm_revenue'])->toBe(1500.0)
        ->and($result['operating_margin_expansion_bps'])->toBe(500.0);
});

test('it returns null when the eight required quarters use inconsistent reporting currencies', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $rows = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [250, 25], [250, 25], [250, 25], [250, 25],
    ]);
    $rows[7]['reported_currency'] = 'CAD';

    expect($analyzer->operatingMarginExpansion($rows)['operating_margin_expansion_bps'])->toBeNull();
});

test('it works regardless of whether the FMP response order is newest-first or oldest-first', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $newestFirst = omeRows([
        [375, 56.25], [375, 56.25], [375, 56.25], [375, 56.25],
        [250, 25], [250, 25], [250, 25], [250, 25],
    ]);
    $oldestFirst = array_reverse($newestFirst);

    expect($analyzer->operatingMarginExpansion($newestFirst)['operating_margin_expansion_bps'])
        ->toBe($analyzer->operatingMarginExpansion($oldestFirst)['operating_margin_expansion_bps'])
        ->toBe(500.0);
});

test('it handles decimal calculations and very large positive expansion', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Current TTM: revenue 1.6B, operating income -80M -> margin -5%
    // Prior TTM: revenue 1.0B, operating income -200M -> margin -20%
    $rows = omeRows([
        [400_000_000, -20_000_000], [400_000_000, -20_000_000], [400_000_000, -20_000_000], [400_000_000, -20_000_000],
        [250_000_000, -50_000_000], [250_000_000, -50_000_000], [250_000_000, -50_000_000], [250_000_000, -50_000_000],
    ]);

    $result = $analyzer->operatingMarginExpansion($rows);

    expect($result['operating_margin_expansion_bps'])->toBe(1500.0);
});
