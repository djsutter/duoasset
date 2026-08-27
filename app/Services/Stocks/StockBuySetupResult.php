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
    public const TYPE_HEARTBEAT_CONSOLIDATION_SPIKE = 'heartbeat_consolidation_spike';

    public int $setupScore = 0;

    public int $heartbeatScore = 0;

    /** Score before liquidity / sleepiness penalties are applied. */
    public int $rawSetupScore = 0;

    /** Average daily volume used by the liquidity turnover calculation. */
    public ?int $avgDailyVolume = null;

    /** Average daily volume divided by float shares or shares outstanding. */
    public ?float $liquidityTurnoverPct = null;

    /** Percent penalty applied to the raw setup score for sleepy liquidity. */
    public float $liquidityPenaltyPct = 0.0;

    /** Number of setup-score points removed by liquidityPenaltyPct. */
    public int $liquidityPenaltyPoints = 0;

    public string $reasonSummary = '';

    public function __construct(
        public readonly string $symbol,
        public readonly string $setupType,
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
        public readonly ?int $spikeAgeBars,
        public readonly int $spikeRarityPoints,
        public readonly string $spikeRarityDescription,
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
        public readonly ?float $quarterlyEpsGrowthPct = null,
        public readonly ?float $quarterlyRevenueGrowthPct = null,
        public readonly ?float $annualEpsGrowthPct = null,
        public readonly ?float $roePct = null,
        public readonly ?float $profitMarginPct = null,
        public readonly ?float $spikeRelativeVolume = null,
        public readonly ?array $epsGrowthSequence = null,
        public readonly ?array $revenueGrowthSequence = null,
        // Operating Margin Expansion (TTM YoY), in basis points, plus the
        // underlying TTM margins retained for score explainability.
        public readonly ?float $operatingMarginExpansionBps = null,
        public readonly ?float $currentTtmOperatingMargin = null,
        public readonly ?float $priorTtmOperatingMargin = null,
        // Inputs to the canonical market_cap = price × shares_outstanding
        // computation. Persisted on the alert row so downstream consumers
        // can recompute / verify market cap without re-fetching the quote.
        public readonly ?float $price = null,
        public readonly ?int $sharesOutstanding = null,
        public readonly ?int $floatShares = null,
        public readonly ?float $freeFloat = null,
    ) {}
}
