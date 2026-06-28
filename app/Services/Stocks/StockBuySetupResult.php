<?php

namespace App\Services\Stocks;

use Carbon\CarbonImmutable;

/**
 * Immutable struct returned by StockBuySetupScanner::evaluate(). Pure
 * data — no DB, no I/O — so it can be safely passed between services and
 * persisted by the EvaluateStockBuySetup job.
 */
final class StockBuySetupResult
{
    public int $heartbeatScore = 0;

    public string $reasonSummary = '';

    public function __construct(
        public readonly string $symbol,
        public readonly ?string $companyName,
        public readonly ?string $exchange,
        public readonly ?int $marketCap,
        public readonly string $marketCapCategory,
        public readonly CarbonImmutable $spikeDate,
        public readonly int $spikeVolume,
        public readonly int $prior52wMaxVolume,
        public readonly int $max104wVolume,
        public readonly bool $is52wHighVolume,
        public readonly bool $is104wHighVolume,
        public readonly ?int $daysSincePreviousComparableSpike,
        public readonly CarbonImmutable $baseStart,
        public readonly CarbonImmutable $baseEnd,
        public readonly int $baseDurationDays,
        public readonly float $baseHigh,
        public readonly float $baseLow,
        public readonly float $rangeCompressionPct,
        public readonly float $atrContractionRatio,
        public readonly float $volumeDryUpScore,
        public readonly float $slope,
        public readonly float $distanceToBreakoutPct,
        public readonly string $maAlignment,
        public readonly ?float $relativeStrengthScore,
        public readonly ?float $earningsAcceleration = null,
        public readonly ?float $salesAcceleration = null,
    ) {}
}
