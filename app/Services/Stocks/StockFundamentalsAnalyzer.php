<?php

namespace App\Services\Stocks;

/**
 * Computes simple CANSLIM-style fundamental quality metrics from normalized
 * quarterly income statement and balance sheet rows.
 *
 * Input rows are expected in ascending or descending date order and may include:
 *   date, eps, revenue, net_income, stockholders_equity
 */
class StockFundamentalsAnalyzer
{
    /**
     * EPS values smaller than one cent are too close to zero to use as a
     * percentage-growth denominator. Without this floor, a fraction-of-a-cent
     * prior EPS can create meaningless multi-thousand-percent growth rates.
     */
    private const MIN_EPS_DENOMINATOR = 0.01;

    /**
     * roe_pct / profit_margin_pct are stored as decimal(10,4) columns
     * (stock_buy_setup_alerts), which can hold at most this magnitude.
     * A positive-but-near-zero revenue/equity denominator (common for
     * distressed or pre-revenue companies) can otherwise produce a
     * multi-million-percent ratio that both overflows the column
     * (SQLSTATE 22003) and is not an economically meaningful percentage
     * anyway, so it is treated the same as missing data: null.
     */
    private const MAX_PCT_MAGNITUDE = 999999.9999;

    /**
     * @param  array<int, array<string, mixed>>  $incomeRows
     * @param  array<int, array<string, mixed>>  $balanceRows
     * @return array<string, mixed>
     */
    public function analyze(array $incomeRows, array $balanceRows = []): array
    {
        $income = $this->sortAscending($incomeRows);
        $balance = $this->sortAscending($balanceRows);

        $epsYoy = $this->yoyGrowthSeries($income, 'eps');
        $revenueYoy = $this->yoyGrowthSeries($income, 'revenue');

        $latest = $income ? $income[array_key_last($income)] : [];
        $latestRevenue = $this->toFloat($latest['revenue'] ?? null);
        $latestNetIncome = $this->toFloat($latest['net_income'] ?? null);

        $latestEquity = null;
        if ($balance) {
            $lastBalance = $balance[array_key_last($balance)];
            $latestEquity = $this->toFloat($lastBalance['stockholders_equity'] ?? null);
        }

        $ttmNetIncome = $this->sumLast($income, 'net_income', 4);

        return array_merge([
            // Acceleration = most recent YoY growth minus previous YoY growth.
            'earnings_acceleration' => $this->acceleration($epsYoy),
            'sales_acceleration' => $this->acceleration($revenueYoy),

            // Raw latest YoY growth metrics for the UI.
            'quarterly_eps_growth_pct' => $this->lastValue($epsYoy),
            'quarterly_revenue_growth_pct' => $this->lastValue($revenueYoy),
            'annual_eps_growth_pct' => $this->annualGrowth($income, 'eps'),
            'profit_margin_pct' => ($latestRevenue !== null && $latestRevenue > 0 && $latestNetIncome !== null)
                ? $this->boundedPercent(($latestNetIncome / $latestRevenue) * 100)
                : null,
            'roe_pct' => ($latestEquity !== null && $latestEquity > 0 && $ttmNetIncome !== null)
                ? $this->boundedPercent(($ttmNetIncome / $latestEquity) * 100)
                : null,
            'eps_growth_sequence' => array_values(array_slice($epsYoy, -4)),
            'revenue_growth_sequence' => array_values(array_slice($revenueYoy, -4)),
        ], $this->operatingMarginExpansion($incomeRows));
    }

    /**
     * Operating Margin Expansion (TTM YoY).
     *
     * Compares the operating margin of the latest four reported quarters
     * (current TTM) against the immediately preceding four quarters (prior
     * TTM), expressed in basis points:
     *
     *   margin_expansion_bps = (current_ttm_margin - prior_ttm_margin) * 10000
     *
     * Requires at least eight valid, distinct, consistently-currencied
     * quarterly income statements with numeric revenue and operating
     * income. Operating income may legitimately be negative — only a null
     * value is treated as missing. Returns all-null when the calculation
     * cannot be performed so the caller can exclude the metric.
     *
     * @param  array<int, array<string, mixed>>  $incomeRows
     * @return array<string, float|null>
     */
    public function operatingMarginExpansion(array $incomeRows): array
    {
        $empty = [
            'current_ttm_revenue' => null,
            'current_ttm_operating_income' => null,
            'current_ttm_operating_margin' => null,
            'prior_ttm_revenue' => null,
            'prior_ttm_operating_income' => null,
            'prior_ttm_operating_margin' => null,
            'operating_margin_expansion_bps' => null,
        ];

        $quarters = $this->dedupeQuartersDescending($incomeRows);

        $valid = array_values(array_filter(
            $quarters,
            fn (array $row) => is_numeric($row['revenue'] ?? null) && is_numeric($row['operating_income'] ?? null),
        ));

        if (count($valid) < 8) {
            return $empty;
        }

        // Q0 (newest) .. Q7 (oldest required quarter).
        $latestEight = array_slice($valid, 0, 8);

        $currencies = array_values(array_unique(array_filter(
            array_map(fn (array $row) => $row['reported_currency'] ?? null, $latestEight),
        )));
        if (count($currencies) > 1) {
            return $empty;
        }

        $current = array_slice($latestEight, 0, 4); // Q0 + Q1 + Q2 + Q3
        $prior = array_slice($latestEight, 4, 4); // Q4 + Q5 + Q6 + Q7

        $currentRevenue = $this->sum($current, 'revenue');
        $currentOperatingIncome = $this->sum($current, 'operating_income');
        $priorRevenue = $this->sum($prior, 'revenue');
        $priorOperatingIncome = $this->sum($prior, 'operating_income');

        if ($currentRevenue === null || $priorRevenue === null || $currentRevenue <= 0 || $priorRevenue <= 0) {
            return $empty;
        }
        if ($currentOperatingIncome === null || $priorOperatingIncome === null) {
            return $empty;
        }

        $currentMargin = $currentOperatingIncome / $currentRevenue;
        $priorMargin = $priorOperatingIncome / $priorRevenue;

        return [
            'current_ttm_revenue' => $currentRevenue,
            'current_ttm_operating_income' => $currentOperatingIncome,
            'current_ttm_operating_margin' => round($currentMargin, 6),
            'prior_ttm_revenue' => $priorRevenue,
            'prior_ttm_operating_income' => $priorOperatingIncome,
            'prior_ttm_operating_margin' => round($priorMargin, 6),
            'operating_margin_expansion_bps' => round(($currentMargin - $priorMargin) * 10000, 4),
        ];
    }

    /**
     * Deduplicate quarterly rows by fiscal quarter (fiscal_year + period
     * when available, falling back to date), keeping the row with the
     * newest date for each quarter, then sort newest-first.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeQuartersDescending(array $rows): array
    {
        $byQuarter = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['date'])) {
                continue;
            }

            $fiscalYear = $row['fiscal_year'] ?? null;
            $period = $row['period'] ?? null;
            $key = ($fiscalYear !== null && $period !== null)
                ? $fiscalYear.'-'.$period
                : (string) $row['date'];

            if (! isset($byQuarter[$key]) || strcmp((string) $row['date'], (string) $byQuarter[$key]['date']) > 0) {
                $byQuarter[$key] = $row;
            }
        }

        $list = array_values($byQuarter);
        usort($list, fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $list;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sortAscending(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => is_array($row) && ! empty($row['date'])));
        usort($rows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $rows;
    }

    /**
     * Preserve one output slot per comparable quarter. Invalid/undefined
     * comparisons are represented as null instead of being removed so that
     * "latest" growth and acceleration can never silently fall back to stale
     * prior-quarter values.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, float|null>
     */
    private function yoyGrowthSeries(array $rows, string $field): array
    {
        $out = [];
        $count = count($rows);

        for ($i = 4; $i < $count; $i++) {
            $current = $this->toFloat($rows[$i][$field] ?? null);
            $prior = $this->toFloat($rows[$i - 4][$field] ?? null);

            if ($current === null || $prior === null) {
                $out[] = null;

                continue;
            }

            if ($field === 'eps') {
                // Percentage EPS growth is economically meaningless when the
                // prior-year EPS is less than one cent in absolute value.
                if (abs($prior) < self::MIN_EPS_DENOMINATOR) {
                    $out[] = null;

                    continue;
                }

                /*
                 * Use the absolute prior EPS as the denominator so that:
                 *
                 * -0.80 -> -0.40 = +50% improvement
                 * -0.40 -> -0.80 = -100% deterioration
                 * -0.10 ->  0.10 = +200% improvement
                 */
                $growth = (($current - $prior) / abs($prior)) * 100;
            } else {
                // Revenue and similar fields require positive comparable values.
                if ($prior <= 0 || $current <= 0) {
                    $out[] = null;

                    continue;
                }

                $growth = (($current - $prior) / $prior) * 100;
            }

            $out[] = round($growth, 4);
        }

        return $out;
    }

    /**
     * Guard a percentage ratio (roe_pct / profit_margin_pct) against both a
     * non-finite result and a magnitude that would overflow its decimal(10,4)
     * database column. See MAX_PCT_MAGNITUDE for details.
     */
    private function boundedPercent(float $value): ?float
    {
        if (! is_finite($value)) {
            return null;
        }

        $rounded = round($value, 4);

        return abs($rounded) > self::MAX_PCT_MAGNITUDE ? null : $rounded;
    }

    /** @param array<int, float|null> $series */
    private function acceleration(array $series): ?float
    {
        $count = count($series);
        if ($count < 2) {
            return null;
        }

        $latest = $series[$count - 1];
        $previous = $series[$count - 2];

        // Acceleration is only meaningful for consecutive valid periods.
        if ($latest === null || $previous === null) {
            return null;
        }

        return round($latest - $previous, 4);
    }

    /** @param array<int, float|null> $series */
    private function lastValue(array $series): ?float
    {
        if (! $series) {
            return null;
        }

        $value = $series[array_key_last($series)];

        return $value === null ? null : (float) $value;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function annualGrowth(array $rows, string $field): ?float
    {
        if (count($rows) < 8) {
            return null;
        }

        $latestFour = array_slice($rows, -4);
        $priorFour = array_slice($rows, -8, 4);
        $latest = $this->sum($latestFour, $field);
        $prior = $this->sum($priorFour, $field);

        if ($latest === null || $prior === null) {
            return null;
        }

        if ($field === 'eps') {
            // Keep the existing CANSLIM-style requirement that both annual
            // periods be profitable, while also preventing a tiny positive
            // prior EPS total from producing an absurd growth percentage.
            if ($latest <= 0 || $prior < self::MIN_EPS_DENOMINATOR) {
                return null;
            }
        } else {
            if ($prior <= 0 || $latest <= 0) {
                return null;
            }
        }

        return round((($latest - $prior) / $prior) * 100, 4);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sumLast(array $rows, string $field, int $count): ?float
    {
        return $this->sum(array_slice($rows, -$count), $field);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sum(array $rows, string $field): ?float
    {
        if (empty($rows)) {
            return null;
        }

        $sum = 0.0;
        $seen = false;
        foreach ($rows as $row) {
            $value = $this->toFloat($row[$field] ?? null);
            if ($value === null) {
                continue;
            }
            $sum += $value;
            $seen = true;
        }

        return $seen ? $sum : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
