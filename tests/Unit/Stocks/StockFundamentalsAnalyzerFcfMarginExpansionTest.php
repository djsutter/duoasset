<?php

use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\ThresholdInterpolationScorer;

/**
 * Builds 8 quarterly income statement rows (newest first) with the given
 * per-quarter revenue values. $quarters[0] is the newest (Q0), $quarters[7]
 * is the oldest required quarter (Q7).
 *
 * @param  array<int, float>  $revenues
 * @return array<int, array<string, mixed>>
 */
function fcfIncomeRows(array $revenues, array $overrides = []): array
{
    $rows = [];
    $date = new DateTimeImmutable('2025-06-30');
    foreach ($revenues as $i => $revenue) {
        $quarterDate = $date->modify('-'.($i * 3).' months');
        $row = [
            'date' => $quarterDate->format('Y-m-d'),
            'revenue' => $revenue,
            'fiscal_year' => (int) $quarterDate->format('Y'),
            'period' => 'Q'.(int) ceil((int) $quarterDate->format('n') / 3),
            'reported_currency' => 'USD',
        ];
        $rows[] = array_merge($row, $overrides[$i] ?? []);
    }

    return $rows;
}

/**
 * Builds 8 quarterly cash flow statement rows (newest first) matching the
 * same dates produced by fcfIncomeRows() for the given free cash flow
 * values. $flows[0] is the newest (Q0), $flows[7] is the oldest required
 * quarter (Q7).
 *
 * @param  array<int, float|null>  $flows
 * @return array<int, array<string, mixed>>
 */
function fcfCashFlowRows(array $flows, array $overrides = []): array
{
    $rows = [];
    $date = new DateTimeImmutable('2025-06-30');
    foreach ($flows as $i => $freeCashFlow) {
        $quarterDate = $date->modify('-'.($i * 3).' months');
        $row = [
            'date' => $quarterDate->format('Y-m-d'),
            'free_cash_flow' => $freeCashFlow,
            'fiscal_year' => (int) $quarterDate->format('Y'),
            'period' => 'Q'.(int) ceil((int) $quarterDate->format('n') / 3),
            'reported_currency' => 'USD',
        ];
        $rows[] = array_merge($row, $overrides[$i] ?? []);
    }

    return $rows;
}

test('it calculates a normal profitable company fcf margin expansion', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Current TTM: revenue 1500, FCF 180 -> margin 12%
    // Prior TTM: revenue 1000, FCF 70 -> margin 7%
    // Expansion = +500 bps
    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([45, 45, 45, 45, 17.5, 17.5, 17.5, 17.5]);

    $result = $analyzer->fcfMarginExpansion($income, $cashFlow);

    expect($result['current_ttm_fcf'])->toBe(180.0)
        ->and($result['current_ttm_revenue_fcf'])->toBe(1500.0)
        ->and($result['current_ttm_fcf_margin'])->toBe(0.12)
        ->and($result['prior_ttm_fcf'])->toBe(70.0)
        ->and($result['prior_ttm_revenue_fcf'])->toBe(1000.0)
        ->and($result['prior_ttm_fcf_margin'])->toBe(0.07)
        ->and($result['fcf_margin_expansion_bps'])->toBe(500.0)
        ->and($result['raw_fcf_margin_expansion_bps'])->toBe(500.0);
});

test('it scores negative-to-less-negative fcf margins when absolute cash burn is also shrinking', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Current TTM: revenue 1500, FCF -75 -> margin -5%
    // Prior TTM: revenue 1000, FCF -200 -> margin -20%
    // Expansion = +1500 bps
    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([-18.75, -18.75, -18.75, -18.75, -50, -50, -50, -50]);

    $result = $analyzer->fcfMarginExpansion($income, $cashFlow);

    expect($result['current_ttm_fcf_margin'])->toBe(-0.05)
        ->and($result['prior_ttm_fcf_margin'])->toBe(-0.2)
        ->and($result['prior_ttm_fcf'])->toBe(-200.0)
        ->and($result['current_ttm_fcf'])->toBe(-75.0)
        ->and($result['fcf_margin_expansion_bps'])->toBe(1500.0)
        ->and($result['raw_fcf_margin_expansion_bps'])->toBe(1500.0);
});

test('it reports fcf margin contraction as a negative bps value', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Current TTM margin 15%, prior TTM margin 20% -> -500 bps
    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 50, 50, 50, 50]);

    $result = $analyzer->fcfMarginExpansion($income, $cashFlow);

    expect($result['fcf_margin_expansion_bps'])->toBe(-500.0);
});

test('it returns null results when fewer than eight valid cash flow quarters are available', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25]);

    $result = $analyzer->fcfMarginExpansion($income, $cashFlow);

    expect($result['fcf_margin_expansion_bps'])->toBeNull()
        ->and($result['current_ttm_fcf_margin'])->toBeNull();
});

test('it treats null free cash flow as missing data but allows negative free cash flow', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);

    $cashFlowWithNull = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25, 25]);
    $cashFlowWithNull[3]['free_cash_flow'] = null;
    expect((new StockFundamentalsAnalyzer)->fcfMarginExpansion($income, $cashFlowWithNull)['fcf_margin_expansion_bps'])->toBeNull();

    $cashFlowWithNegative = fcfCashFlowRows([-56.25, -56.25, -56.25, -56.25, 25, 25, 25, 25]);
    expect($analyzer->fcfMarginExpansion($income, $cashFlowWithNegative)['fcf_margin_expansion_bps'])->not->toBeNull();
});

test('it returns null when there is no matching revenue for the cash flow quarter dates', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25, 25]);
    $cashFlow[7]['date'] = '1999-01-01'; // no matching income statement row

    expect($analyzer->fcfMarginExpansion($income, $cashFlow)['fcf_margin_expansion_bps'])->toBeNull();
});

test('it returns null when a rolling ttm revenue total is zero', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fcfIncomeRows([375, 375, 375, 375, 0, 0, 0, 0]);
    $cashFlow = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25, 25]);

    expect($analyzer->fcfMarginExpansion($income, $cashFlow)['fcf_margin_expansion_bps'])->toBeNull();
});

test('it returns null when the eight required cash flow quarters use inconsistent reporting currencies', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25, 25]);
    $cashFlow[7]['reported_currency'] = 'CAD';

    expect($analyzer->fcfMarginExpansion($income, $cashFlow)['fcf_margin_expansion_bps'])->toBeNull();
});

test('it works regardless of whether the FMP response order is newest-first or oldest-first', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $newestFirst = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25, 25]);
    $oldestFirst = array_reverse($newestFirst);

    expect($analyzer->fcfMarginExpansion($income, $newestFirst)['fcf_margin_expansion_bps'])
        ->toBe($analyzer->fcfMarginExpansion($income, $oldestFirst)['fcf_margin_expansion_bps'])
        ->toBe(500.0);
});

test('it deduplicates repeated fiscal quarters instead of double counting them', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25, 25]);

    // Duplicate Q0 (index 0) with identical fiscal_year/period.
    $duplicate = $cashFlow[0];
    array_unshift($cashFlow, $duplicate);

    $result = $analyzer->fcfMarginExpansion($income, $cashFlow);

    // Still exactly 500 bps expansion — duplicate must not be double-counted.
    expect($result['current_ttm_fcf'])->toBe(225.0)
        ->and($result['fcf_margin_expansion_bps'])->toBe(500.0);
});

test('it returns all null when the income statement rows lack usable revenue', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = [];
    $cashFlow = fcfCashFlowRows([56.25, 56.25, 56.25, 56.25, 25, 25, 25, 25]);

    expect($analyzer->fcfMarginExpansion($income, $cashFlow)['fcf_margin_expansion_bps'])->toBeNull();
});

// Regression: rapid revenue growth must not turn rising cash consumption into
// a full-weight FCF improvement score.
test('it does not reward improving fcf margins when absolute cash burn worsens', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // Prior TTM: revenue $10M, FCF -$20M -> -200% margin.
    // Current TTM: revenue $40M, FCF -$30M -> -75% margin.
    // The raw +12,500 bps expansion is genuine, but the extra $10M of burn
    // disqualifies it from the scoring input.
    $income = fcfIncomeRows(array_merge(array_fill(0, 4, 10_000_000), array_fill(0, 4, 2_500_000)));
    $cashFlow = fcfCashFlowRows(array_merge(array_fill(0, 4, -7_500_000), array_fill(0, 4, -5_000_000)));

    $result = $analyzer->fcfMarginExpansion($income, $cashFlow);

    expect($result['prior_ttm_fcf'])->toBe(-20_000_000.0)
        ->and($result['current_ttm_fcf'])->toBe(-30_000_000.0)
        ->and($result['prior_ttm_fcf_margin'])->toBe(-2.0)
        ->and($result['current_ttm_fcf_margin'])->toBe(-0.75)
        ->and($result['raw_fcf_margin_expansion_bps'])->toBe(12_500.0)
        ->and($result['fcf_margin_expansion_bps'])->toBe(0.0);

    $interpolator = new ThresholdInterpolationScorer;
    expect($interpolator->score($result['raw_fcf_margin_expansion_bps'], 250, 500, 1000, 1500))->toBe(100.0)
        ->and($interpolator->score($result['fcf_margin_expansion_bps'], 250, 500, 1000, 1500))->toBe(0.0);
});

test('it still rewards reducing cash burn and the transition to positive fcf', function () {
    $analyzer = new StockFundamentalsAnalyzer;
    $income = fcfIncomeRows(array_merge(array_fill(0, 4, 10_000_000), array_fill(0, 4, 2_500_000)));
    $prior = array_fill(0, 4, -5_000_000);

    // Current FCF improves from -$20M to -$15M.
    $reducedBurn = $analyzer->fcfMarginExpansion($income, fcfCashFlowRows(array_merge(array_fill(0, 4, -3_750_000), $prior)));
    expect($reducedBurn['fcf_margin_expansion_bps'])->toBe(16_250.0)
        ->and($reducedBurn['raw_fcf_margin_expansion_bps'])->toBe(16_250.0);

    // Current FCF turns positive: from -$20M to +$2M.
    $positive = $analyzer->fcfMarginExpansion($income, fcfCashFlowRows(array_merge(array_fill(0, 4, 500_000), $prior)));
    expect($positive['fcf_margin_expansion_bps'])->toBe(20_500.0)
        ->and($positive['raw_fcf_margin_expansion_bps'])->toBe(20_500.0);
});

test('it does not substitute a ninth older quarter for invalid recent cash flow or revenue', function () {
    $analyzer = new StockFundamentalsAnalyzer;
    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([45, 45, 45, 45, 17.5, 17.5, 17.5, 17.5, 17.5]);

    // Previous code silently skipped the missing newest Q0 and used Q1..Q8.
    $missingFcf = $cashFlow;
    $missingFcf[0]['free_cash_flow'] = null;
    $result = $analyzer->fcfMarginExpansion($income, $missingFcf);
    expect($result['fcf_margin_expansion_bps'])->toBeNull()
        ->and($result['raw_fcf_margin_expansion_bps'])->toBeNull();

    $missingRevenue = $income;
    $missingRevenue[0]['revenue'] = null;
    $result = $analyzer->fcfMarginExpansion($missingRevenue, $cashFlow);
    expect($result['fcf_margin_expansion_bps'])->toBeNull()
        ->and($result['raw_fcf_margin_expansion_bps'])->toBeNull();
});

test('it rejects mismatched income and cash flow reporting currencies', function () {
    $analyzer = new StockFundamentalsAnalyzer;
    $income = fcfIncomeRows([375, 375, 375, 375, 250, 250, 250, 250]);
    $cashFlow = fcfCashFlowRows([45, 45, 45, 45, 17.5, 17.5, 17.5, 17.5]);

    // Every cash-flow row is in USD, but one matching income row is in CAD.
    $income[7]['reported_currency'] = 'CAD';
    $result = $analyzer->fcfMarginExpansion($income, $cashFlow);
    expect($result['fcf_margin_expansion_bps'])->toBeNull()
        ->and($result['raw_fcf_margin_expansion_bps'])->toBeNull();
});
