<?php

use App\Services\Stocks\StockFundamentalsAnalyzer;

/**
 * Builds quarterly income statement rows (oldest first) with the given
 * per-quarter revenue/net_income/eps values.
 *
 * @param  array<int, array{revenue: float|null, net_income: float|null, eps?: float|null}>  $quarters
 * @return array<int, array<string, mixed>>
 */
function fundamentalsIncomeRows(array $quarters): array
{
    $rows = [];
    $date = new DateTimeImmutable('2023-01-01');
    foreach ($quarters as $i => $q) {
        $quarterDate = $date->modify('+'.($i * 3).' months');
        $rows[] = [
            'date' => $quarterDate->format('Y-m-d'),
            'revenue' => $q['revenue'] ?? null,
            'net_income' => $q['net_income'] ?? null,
            'eps' => $q['eps'] ?? null,
        ];
    }

    return $rows;
}

test('it calculates a normal profit margin and roe for a healthy company', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fundamentalsIncomeRows([
        ['revenue' => 1000, 'net_income' => 100],
        ['revenue' => 1000, 'net_income' => 100],
        ['revenue' => 1000, 'net_income' => 100],
        ['revenue' => 1000, 'net_income' => 100], // latest quarter: profit margin = 10%
    ]);
    $balance = [['date' => '2023-10-01', 'stockholders_equity' => 800]];

    $result = $analyzer->analyze($income, $balance);

    // profit_margin_pct: latest quarter net_income (100) / revenue (1000) * 100
    expect($result['profit_margin_pct'])->toBe(10.0)
        // roe_pct: ttm net_income (400) / equity (800) * 100
        ->and($result['roe_pct'])->toBe(50.0);
});

test('it nulls out profit_margin_pct instead of overflowing the decimal(10,4) database column', function () {
    // Reproduces the production SQLSTATE[22003] "Out of range value for
    // column 'profit_margin_pct'" bug: near-zero revenue with a much
    // larger (negative) net income produces a multi-million-percent
    // ratio that cannot fit in decimal(10,4) (max magnitude 999999.9999).
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fundamentalsIncomeRows([
        ['revenue' => 1000, 'net_income' => -11188750],
    ]);

    $result = $analyzer->analyze($income);

    // Raw ratio would be -1,118,875% — well beyond the column's capacity.
    expect($result['profit_margin_pct'])->toBeNull();
});

test('it nulls out roe_pct instead of overflowing the decimal(10,4) database column', function () {
    // Reproduces the production SQLSTATE[22003] "Out of range value for
    // column 'roe_pct'" bug: a positive-but-near-zero equity denominator
    // with a much larger TTM net income produces a multi-million-percent
    // ratio that cannot fit in decimal(10,4).
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fundamentalsIncomeRows([
        ['revenue' => 1000, 'net_income' => -2797187.5],
        ['revenue' => 1000, 'net_income' => -2797187.5],
        ['revenue' => 1000, 'net_income' => -2797187.5],
        ['revenue' => 1000, 'net_income' => -2797187.5], // ttm net_income = -11,188,750
    ]);
    $balance = [['date' => '2023-10-01', 'stockholders_equity' => 1000]];

    $result = $analyzer->analyze($income, $balance);

    // Raw ratio would be -1,118,875% — well beyond the column's capacity.
    expect($result['roe_pct'])->toBeNull();
});

test('it still returns a value that sits exactly at the database column boundary', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    // net_income / revenue * 100 = 999999.9999% exactly (the maximum a
    // decimal(10,4) column can store), so it must be kept, not nulled.
    $income = fundamentalsIncomeRows([
        ['revenue' => 100, 'net_income' => 999999.9999],
    ]);

    $result = $analyzer->analyze($income);

    expect($result['profit_margin_pct'])->toBe(999999.9999);
});

test('it nulls out profit_margin_pct and roe_pct when the denominator is zero or negative, same as before', function () {
    $analyzer = new StockFundamentalsAnalyzer;

    $income = fundamentalsIncomeRows([
        ['revenue' => 0, 'net_income' => 100],
    ]);
    $balance = [['date' => '2023-10-01', 'stockholders_equity' => -500]];

    $result = $analyzer->analyze($income, $balance);

    expect($result['profit_margin_pct'])->toBeNull()
        ->and($result['roe_pct'])->toBeNull();
});
