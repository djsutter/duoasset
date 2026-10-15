<?php

use App\Services\Stocks\StockFundamentalsAnalyzer;

/** @return array<int, array<string, mixed>> */
function revenueAccelerationQuarters(array $revenues): array
{
    $rows = [];
    foreach ($revenues as $i => $revenue) {
        $date = (new DateTimeImmutable('2024-09-30'))->modify('+'.($i * 3).' months');
        $rows[] = [
            'date' => $date->format('Y-m-d'),
            'fiscal_year' => (int) $date->format('Y'),
            'period' => 'Q'.(int) ceil(((int) $date->format('n')) / 3),
            'reported_currency' => 'USD',
            'revenue' => $revenue,
            'eps' => 1.0,
            'net_income' => 10.0,
        ];
    }
    return $rows;
}

test('NBIS-style comparable revenue periods compute YoY growth deceleration in percentage points', function () {
    $rows = revenueAccelerationQuarters([32.1, 35.2, 50.9, 105.1, 146.1, 227.7, 399.0, 582.3]);
    $result = (new StockFundamentalsAnalyzer)->analyze($rows);
    expect($result['quarterly_revenue_growth_pct'])->toBe(454.0438)
        ->and($result['sales_acceleration'])->toBe(-229.8462)
        ->and($result['revenue_growth_sequence'])->toHaveCount(4);
});

test('a repeated fiscal quarter does not shift last years YoY comparator', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 300]);
    $rows[] = $rows[6]; // duplicate Q1 2026, same filing
    $result = (new StockFundamentalsAnalyzer)->analyze($rows);
    expect($result['quarterly_revenue_growth_pct'])->toBe(round((300 / 130 - 1) * 100, 4))
        ->and($result['sales_acceleration'])->toBe(round((300 / 130 - 250 / 120) * 100, 4));
});

test('later-filed restatement takes precedence over the older same-quarter revenue', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 300]);
    $rows[2]['filing_date'] = '2025-06-01';
    $older = $rows[2];
    $rows[2]['revenue'] = 100;
    $rows[2]['filing_date'] = '2026-09-01';
    $rows[] = $older;

    $result = (new StockFundamentalsAnalyzer)->analyze($rows);
    expect($result['revenue_growth_sequence'][2])->toBe(150.0);
});

test('missing quarters must not produce comparisons to unrelated prior-year quarters', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 300]);
    unset($rows[2]); // Missing Q1 2025 means Q1 2026 cannot match i-4.
    $result = (new StockFundamentalsAnalyzer)->analyze(array_values($rows));
    expect($result['sales_acceleration'])->toBeNull()
        ->and($result['quarterly_revenue_growth_pct'])->toBe(round((300 / 130 - 1) * 100, 4));
});

test('mismatched reporting currencies invalidate the YoY and acceleration calculations', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 300]);
    $rows[3]['reported_currency'] = 'CAD';
    $result = (new StockFundamentalsAnalyzer)->analyze($rows);
    expect($result['quarterly_revenue_growth_pct'])->toBeNull()
        ->and($result['sales_acceleration'])->toBeNull();
});

test('a missing prior quarter does not produce an annual EPS growth from an incomplete TTM', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 300]);
    $rows[0]['eps'] = null;
    $rows[4]['net_income'] = null;
    $result = (new StockFundamentalsAnalyzer)->analyze($rows, [['date' => '2026-06-30', 'stockholders_equity' => 100]]);
    expect($result['annual_eps_growth_pct'])->toBeNull()
        ->and($result['roe_pct'])->toBeNull();
});

test('missing a quarter disqualifies rolling TTM operating margin, not just YoY growth', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 300]);
    foreach ($rows as &$row) {
        $row['operating_income'] = -10.0;
    }
    unset($row);
    $rows[2]['date'] = '2024-08-31';
    $rows[2]['fiscal_year'] = 2024;
    $rows[2]['period'] = 'Q3'; // duplicates Q3 2024, Q1 2025 missing
    expect((new StockFundamentalsAnalyzer)->operatingMarginExpansion($rows)['operating_margin_expansion_bps'])->toBeNull();
});

test('a complete revenue collapse is minus 100 percent, not missing data', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 0]);
    $result = (new StockFundamentalsAnalyzer)->analyze($rows);
    expect($result['quarterly_revenue_growth_pct'])->toBe(-100.0)
        ->and($result['sales_acceleration'])->toBe(round(-100 - (250 / 120 - 1) * 100, 4));
});

test('ROE is not fabricated from a stale or differently-currencied balance sheet', function () {
    $rows = revenueAccelerationQuarters([100, 110, 120, 130, 160, 200, 250, 300]);
    $stale = [['date' => '2025-06-30', 'stockholders_equity' => 100.0, 'reported_currency' => 'USD']];
    $foreign = [['date' => '2026-06-30', 'stockholders_equity' => 100.0, 'reported_currency' => 'CAD']];
    $valid = [['date' => '2026-06-30', 'stockholders_equity' => 100.0, 'reported_currency' => 'USD']];
    $analyzer = new StockFundamentalsAnalyzer;
    expect($analyzer->analyze($rows, $stale)['roe_pct'])->toBeNull()
        ->and($analyzer->analyze($rows, $foreign)['roe_pct'])->toBeNull()
        ->and($analyzer->analyze($rows, $valid)['roe_pct'])->toBe(40.0);
});
