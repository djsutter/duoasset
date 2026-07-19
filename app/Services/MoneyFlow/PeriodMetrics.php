<?php

namespace App\Services\MoneyFlow;

/**
 * One ETF's metrics for a single timeframe (hourly/daily/weekly/monthly).
 *
 * All fields are computed against the ETF's own history and the benchmark;
 * `score` is the 0-100 absolute component blend. `hasData` is false when the
 * ETF lacked enough bars for this timeframe (its metrics are then ignored by
 * the aggregator rather than counted as zero).
 */
final class PeriodMetrics
{
    public function __construct(
        public readonly bool $hasData,
        public readonly ?float $changePct = null,
        public readonly ?float $relativeStrength = null,
        public readonly ?float $relativeVolume = null,
        public readonly ?float $relativeDollarVolume = null,
        public readonly ?float $score = null,
        public readonly bool $outperforms = false,
    ) {}

    public static function empty(): self
    {
        return new self(hasData: false);
    }
}
