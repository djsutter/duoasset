<?php

namespace App\Services\MoneyFlow;

/**
 * Aggregated sector metrics for a single timeframe — the weighted combination
 * of its constituent ETFs' PeriodMetrics.
 */
final class SectorPeriodMetrics
{
    public function __construct(
        public readonly bool $hasData,
        public readonly ?float $changePct = null,
        public readonly ?float $relativeStrength = null,
        public readonly ?float $relativeVolume = null,
        public readonly ?float $relativeDollarVolume = null,
        public readonly ?float $score = null,
        public readonly ?float $issuerBreadth = null,
    ) {}

    public static function empty(): self
    {
        return new self(hasData: false);
    }
}
