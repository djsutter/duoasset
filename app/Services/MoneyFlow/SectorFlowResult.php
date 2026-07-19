<?php

namespace App\Services\MoneyFlow;

/**
 * The aggregated money-flow result for one sector at one capture — the output
 * of SectorFlowAggregator, before cross-sectional ranking and temporal
 * velocity/acceleration are layered on by SectorMoneyFlowService.
 */
final class SectorFlowResult
{
    /**
     * @param  array<string, SectorPeriodMetrics>  $periods  keyed by timeframe
     * @param  array<string, array<string, mixed>>  $constituents  keyed by symbol
     */
    public function __construct(
        public readonly string $sector,
        public readonly string $label,
        public readonly array $periods,
        public readonly ?float $strength,
        public readonly int $etfCount,
        public readonly float $confidenceScore,
        public readonly float $dataQualityScore,
        public readonly array $constituents,
    ) {}

    public function period(string $timeframe): SectorPeriodMetrics
    {
        return $this->periods[$timeframe] ?? SectorPeriodMetrics::empty();
    }

    public function score(string $timeframe): ?float
    {
        return $this->period($timeframe)->score;
    }
}
