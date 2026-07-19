<?php

namespace App\Services\MoneyFlow;

/**
 * Computes one ETF's per-timeframe money-flow metrics from its OHLCV bars and
 * the benchmark's bars, then normalizes them into 0-100 component scores via
 * SectorFlowScorer.
 *
 * Timeframes use trading BARS, never calendar-day subtraction:
 *   - hourly            -> the 1-hour intraday series
 *   - daily/weekly/monthly -> the daily series (N sessions back)
 *
 * The class is pure given its inputs (no I/O); the service supplies the bars.
 */
class SectorEtfMetricsCalculator
{
    /** @var array<string, int> */
    private array $periods;

    private int $baselineSessions;

    private int $intradayRvolBars;

    public function __construct(private readonly SectorFlowScorer $scorer)
    {
        $cfg = config('market_data.moneyflow');

        $this->periods = array_map('intval', (array) ($cfg['periods'] ?? []));
        $this->baselineSessions = (int) ($cfg['baseline_lookback_sessions'] ?? 60);
        $this->intradayRvolBars = (int) ($cfg['intraday']['relative_volume_lookback_bars'] ?? 20);
    }

    /**
     * @param  array<int, array<string, mixed>>  $dailyBars  ascending daily OHLCV
     * @param  array<int, array<string, mixed>>  $hourlyBars  ascending 1h OHLCV
     * @param  array<int, array<string, mixed>>  $benchmarkDailyBars
     * @param  array<int, array<string, mixed>>  $benchmarkHourlyBars
     */
    public function calculate(
        string $symbol,
        string $issuer,
        float $weight,
        array $dailyBars,
        array $hourlyBars,
        array $benchmarkDailyBars,
        array $benchmarkHourlyBars,
    ): EtfMetrics {
        if (count($dailyBars) < 2) {
            return EtfMetrics::invalid($symbol, $issuer, $weight, 'insufficient daily bars');
        }

        $periods = [];
        $withData = 0;
        $total = 0;

        foreach (['hourly', 'daily', 'weekly', 'monthly'] as $timeframe) {
            $total++;
            $isHourly = $timeframe === 'hourly';
            $bars = $isHourly ? $hourlyBars : $dailyBars;
            $benchmark = $isHourly ? $benchmarkHourlyBars : $benchmarkDailyBars;
            $n = max(1, $this->periods[$timeframe] ?? 1);
            $rvolBaseline = $isHourly ? $this->intradayRvolBars : $this->baselineSessions;

            $metrics = $this->periodMetrics($bars, $benchmark, $n, $rvolBaseline, $timeframe);
            $periods[$timeframe] = $metrics;
            if ($metrics->hasData) {
                $withData++;
            }
        }

        $currentPrice = $this->lastClose($dailyBars) ?? $this->lastClose($hourlyBars);
        $dataQuality = $total > 0 ? ($withData / $total) * 100.0 : 0.0;

        return new EtfMetrics(
            symbol: $symbol,
            issuer: $issuer,
            weight: $weight,
            valid: true,
            error: null,
            currentPrice: $currentPrice,
            dataQualityScore: $dataQuality,
            periods: $periods,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     * @param  array<int, array<string, mixed>>  $benchmark
     */
    private function periodMetrics(array $bars, array $benchmark, int $n, int $rvolBaseline, string $timeframe): PeriodMetrics
    {
        $closes = $this->column($bars, 'close');
        $volumes = $this->column($bars, 'volume');

        $return = $this->periodReturn($closes, $n);
        if ($return === null) {
            return PeriodMetrics::empty();
        }

        $benchReturn = $this->periodReturn($this->column($benchmark, 'close'), $n);
        $relativeStrength = $benchReturn === null ? null : $return - $benchReturn;

        $sigma = $this->rollingReturnStdDev($closes, $n, $this->baselineSessions);
        $relativeVolume = $this->relativeVolume($volumes, $n, $rvolBaseline);
        $relativeDollarVolume = $this->relativeDollarVolume($closes, $volumes, $n, $rvolBaseline);

        $changeScore = $this->scorer->scoreChange($return, $sigma);
        $rsScore = $this->scorer->scoreRelativeStrength($relativeStrength, $timeframe);
        $rvolScore = $this->scorer->scoreRelativeVolume($relativeVolume);
        $score = $this->scorer->blendComponents($changeScore, $rsScore, $rvolScore);

        return new PeriodMetrics(
            hasData: true,
            changePct: $return,
            relativeStrength: $relativeStrength,
            relativeVolume: $relativeVolume,
            relativeDollarVolume: $relativeDollarVolume,
            score: $score,
            outperforms: $relativeStrength !== null && $relativeStrength > 0.0,
        );
    }

    /**
     * Percentage return over the last $n bars: (last / n-bars-ago - 1) * 100.
     *
     * @param  array<int, float>  $closes
     */
    private function periodReturn(array $closes, int $n): ?float
    {
        $count = count($closes);
        if ($count < $n + 1) {
            return null;
        }

        $now = $closes[$count - 1];
        $then = $closes[$count - 1 - $n];
        if ($then <= 0.0) {
            return null;
        }

        return (($now / $then) - 1.0) * 100.0;
    }

    /**
     * Std dev (sample) of the ETF's own trailing N-bar returns — the baseline
     * the period return is scored against. Null when too few observations.
     *
     * @param  array<int, float>  $closes
     */
    private function rollingReturnStdDev(array $closes, int $n, int $baseline): ?float
    {
        $count = count($closes);
        if ($count < $n + 2) {
            return null;
        }

        $returns = [];
        for ($i = $n; $i < $count; $i++) {
            $then = $closes[$i - $n];
            if ($then <= 0.0) {
                continue;
            }
            $returns[] = (($closes[$i] / $then) - 1.0) * 100.0;
        }

        if ($baseline > 0 && count($returns) > $baseline) {
            $returns = array_slice($returns, count($returns) - $baseline);
        }

        return $this->stdDev($returns);
    }

    /**
     * Mean volume over the last $n bars vs the mean over the baseline window.
     *
     * @param  array<int, float>  $volumes
     */
    private function relativeVolume(array $volumes, int $n, int $baseline): ?float
    {
        $recent = $this->tailMean($volumes, $n);
        $base = $this->tailMean($volumes, $baseline);
        if ($recent === null || $base === null || $base <= 0.0) {
            return null;
        }

        return $recent / $base;
    }

    /**
     * @param  array<int, float>  $closes
     * @param  array<int, float>  $volumes
     */
    private function relativeDollarVolume(array $closes, array $volumes, int $n, int $baseline): ?float
    {
        $count = min(count($closes), count($volumes));
        if ($count === 0) {
            return null;
        }

        $dollar = [];
        for ($i = 0; $i < $count; $i++) {
            $dollar[] = $closes[$i] * $volumes[$i];
        }

        $recent = $this->tailMean($dollar, $n);
        $base = $this->tailMean($dollar, $baseline);
        if ($recent === null || $base === null || $base <= 0.0) {
            return null;
        }

        return $recent / $base;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<int, float>
     */
    private function column(array $bars, string $key): array
    {
        return array_map(static fn ($bar) => (float) ($bar[$key] ?? 0), $bars);
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function lastClose(array $bars): ?float
    {
        if ($bars === []) {
            return null;
        }
        $last = $bars[count($bars) - 1];
        $close = $last['close'] ?? null;

        return $close !== null ? (float) $close : null;
    }

    /**
     * Mean of the last $count values (or all if fewer). Null if empty.
     *
     * @param  array<int, float>  $values
     */
    private function tailMean(array $values, int $count): ?float
    {
        $len = count($values);
        if ($len === 0 || $count <= 0) {
            return null;
        }
        $slice = $len > $count ? array_slice($values, $len - $count) : $values;

        return array_sum($slice) / count($slice);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function stdDev(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) {
            $sumSq += ($v - $mean) ** 2;
        }

        return sqrt($sumSq / ($n - 1));
    }
}
