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

        return [
            // Acceleration = most recent YoY growth minus previous YoY growth.
            'earnings_acceleration' => $this->acceleration($epsYoy),
            'sales_acceleration' => $this->acceleration($revenueYoy),

            // Raw latest YoY growth metrics for the UI.
            'quarterly_eps_growth_pct' => $this->lastValue($epsYoy),
            'quarterly_revenue_growth_pct' => $this->lastValue($revenueYoy),
            'annual_eps_growth_pct' => $this->annualGrowth($income, 'eps'),
            'profit_margin_pct' => ($latestRevenue !== null && $latestRevenue > 0 && $latestNetIncome !== null)
                ? round(($latestNetIncome / $latestRevenue) * 100, 4)
                : null,
            'roe_pct' => ($latestEquity !== null && $latestEquity > 0 && $ttmNetIncome !== null)
                ? round(($ttmNetIncome / $latestEquity) * 100, 4)
                : null,
            'eps_growth_sequence' => array_values(array_slice($epsYoy, -4)),
            'revenue_growth_sequence' => array_values(array_slice($revenueYoy, -4)),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sortAscending(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => is_array($row) && ! empty($row['date'])));
        usort($rows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, float>
     */
    private function yoyGrowthSeries(array $rows, string $field): array
    {
        $out = [];
        $count = count($rows);

        for ($i = 4; $i < $count; $i++) {
            $current = $this->toFloat($rows[$i][$field] ?? null);
            $prior = $this->toFloat($rows[$i - 4][$field] ?? null);

            if ($current === null || $prior === null) {
                continue;
            }

            if ($field === 'eps') {
                // A zero prior EPS cannot produce a meaningful percentage change.
                if (abs($prior) < 0.000001) {
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
                    continue;
                }

                $growth = (($current - $prior) / $prior) * 100;
            }

            $out[] = round($growth, 4);
        }

        return $out;
    }

    /** @param array<int, float> $series */
    private function acceleration(array $series): ?float
    {
        $count = count($series);
        if ($count < 2) {
            return null;
        }

        return round($series[$count - 1] - $series[$count - 2], 4);
    }

    /** @param array<int, float> $series */
    private function lastValue(array $series): ?float
    {
        return $series ? $series[array_key_last($series)] : null;
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

        if ($latest === null || $prior === null || $prior <= 0 || $latest <= 0) {
            return null;
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
