<?php

namespace App\Services\Stocks;

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\Algorithms\BuySetupAlgorithmRegistry;

/**
 * Produces a configurable normalized 0-100 setup score.
 *
 * Component weights live in config('market_data.buy_setup_scanner.score_weights').
 * Raw component points are summed, then normalized against the configured
 * maximum possible points. This lets the weights total 80, 100, 110, etc.
 * while the displayed setup score remains a true 0-100 ranking value.
 */
class StockBuySetupScorer
{
    /**
     * @return array<string, array{label: string, points: int, max: int, value?: string}>
     */
    public function breakdown(
        StockBuySetupResult|StockBuySetupAlert $r,
        ?string $setupType = null,
        ?float $priorYearRevenue = null,
    ): array {
        $type = $setupType ?? ($r->setupType ?? $r->setup_type ?? null);
        $weights = $this->weights($type);
        $scales = $this->accelerationScales();

        $salesAccelerationPoints = $this->logarithmicBonusPoints(
            $this->nullableFloat($r->salesAcceleration ?? $r->sales_acceleration ?? null),
            $weights['sales_acceleration'],
            $scales['sales_acceleration'],
        );

        $priorYear = $priorYearRevenue ?? $this->nullableFloat($r->priorYearRevenue ?? $r->prior_year_revenue ?? null);

        $salesAccelerationPoints = $this->applyPriorYearRevenuePenalty(
            $salesAccelerationPoints,
            $priorYear,
            $type,
        );

        return [
            'spike_rarity' => [
                'label' => 'Spike rarity',
                'points' => $this->spikeRarityPoints($r, $weights['spike_rarity']),
                'max' => $weights['spike_rarity'],
                'value' => (string) ($r->spikeRarityDescription ?? $r->spike_rarity_description ?? 'No qualifying spike in the last 104 weeks'),
            ],
            'base_duration' => [
                'label' => 'Base duration',
                'points' => $this->baseDurationPoints((int) ($r->baseDurationDays ?? $r->base_duration_days ?? 0), $weights['base_duration']),
                'max' => $weights['base_duration'],
                'value' => (string) ((int) ($r->baseDurationDays ?? $r->base_duration_days ?? 0)).' trading days',
            ],
            'range_compression' => [
                'label' => 'Range compression',
                'points' => $this->rangeCompressionPoints((float) ($r->rangeCompressionPct ?? $r->range_compression_pct ?? 999), $weights['range_compression']),
                'max' => $weights['range_compression'],
                'value' => number_format((float) ($r->rangeCompressionPct ?? $r->range_compression_pct ?? 0), 2).'%',
            ],
            'atr_contraction' => [
                'label' => 'ATR contraction',
                'points' => $this->atrContractionPoints((float) ($r->atrContractionRatio ?? $r->atr_contraction_ratio ?? 999), $weights['atr_contraction']),
                'max' => $weights['atr_contraction'],
                'value' => number_format((float) ($r->atrContractionRatio ?? $r->atr_contraction_ratio ?? 0), 2),
            ],
            'volume_dry_up' => [
                'label' => 'Volume dry-up',
                'points' => $this->volumeDryUpPoints((float) ($r->volumeDryUpScore ?? $r->volume_dry_up_score ?? 0), $weights['volume_dry_up']),
                'max' => $weights['volume_dry_up'],
                'value' => number_format(((float) ($r->volumeDryUpScore ?? $r->volume_dry_up_score ?? 0)) * 100, 1).'%',
            ],
            'breakout_distance' => [
                'label' => 'Breakout distance',
                'points' => $this->breakoutDistancePoints((float) ($r->distanceToBreakoutPct ?? $r->distance_to_breakout_pct ?? 999), $weights['breakout_distance']),
                'max' => $weights['breakout_distance'],
                'value' => number_format((float) ($r->distanceToBreakoutPct ?? $r->distance_to_breakout_pct ?? 0), 2).'%',
            ],
            'ma_alignment' => [
                'label' => 'MA alignment',
                'points' => $this->maAlignmentPoints(
                    (string) ($r->maAlignment ?? $r->ma_alignment ?? ''),
                    $weights['ma_alignment'],
                    BuySetupAlgorithmRegistry::isReversalStyle(app(BuySetupConfigService::class)->getSetupAlgorithm($type)),
                ),
                'max' => $weights['ma_alignment'],
                'value' => (string) ($r->maAlignment ?? $r->ma_alignment ?? ''),
            ],
            'relative_strength' => [
                'label' => 'Relative strength',
                'points' => $this->relativeStrengthPoints($this->nullableFloat($r->relativeStrengthScore ?? $r->relative_strength_score ?? null), $weights['relative_strength']),
                'max' => $weights['relative_strength'],
                'value' => ($this->nullableFloat($r->relativeStrengthScore ?? $r->relative_strength_score ?? null) !== null) ? number_format((float) ($r->relativeStrengthScore ?? $r->relative_strength_score), 1) : 'n/a',
            ],
            'earnings_acceleration' => [
                'label' => 'Earnings accel.',
                'points' => $this->logarithmicBonusPoints(
                    $this->nullableFloat($r->earningsAcceleration ?? $r->earnings_acceleration ?? null),
                    $weights['earnings_acceleration'],
                    $scales['earnings_acceleration'],
                ),
                'max' => $weights['earnings_acceleration'],
                'value' => ($this->nullableFloat($r->earningsAcceleration ?? $r->earnings_acceleration ?? null) !== null) ? number_format((float) ($r->earningsAcceleration ?? $r->earnings_acceleration), 1).' pts' : 'n/a',
            ],
            'sales_acceleration' => [
                'label' => 'Sales accel.',
                'points' => $salesAccelerationPoints,
                'max' => $weights['sales_acceleration'],
                'value' => ($this->nullableFloat($r->salesAcceleration ?? $r->sales_acceleration ?? null) !== null) ? number_format((float) ($r->salesAcceleration ?? $r->sales_acceleration), 1).' pts' : 'n/a',
            ],
            'operating_margin_expansion' => [
                'label' => 'Operating improvement (loss-validated)',
                'points' => $this->operatingMarginExpansionPoints(
                    $this->nullableFloat($r->operatingMarginExpansionBps ?? $r->operating_margin_expansion_bps ?? null),
                    $weights['operating_margin_expansion'] ?? 0,
                    $type,
                ),
                'max' => $weights['operating_margin_expansion'] ?? 0,
                'value' => $this->operatingMarginExpansionValue($r),
            ],
            'fcf_margin_expansion' => [
                'label' => 'FCF improvement (cash-burn-validated)',
                'points' => $this->fcfMarginExpansionPoints(
                    $this->nullableFloat($r->fcfMarginExpansionBps ?? $r->fcf_margin_expansion_bps ?? null),
                    $weights['fcf_margin_expansion'] ?? 0,
                    $type,
                ),
                'max' => $weights['fcf_margin_expansion'] ?? 0,
                'value' => $this->fcfMarginExpansionValue($r),
            ],
            'book_value' => $this->bookValueBreakdownEntry($r, $type, $weights['book_value'] ?? 0),
        ];
    }

    public function score(
        StockBuySetupResult $r,
        ?string $setupType = null,
        ?float $priorYearRevenue = null,
    ): int {
        return $this->scoreFromBreakdown($this->breakdown($r, $setupType, $priorYearRevenue));
    }

    public function scoreFromAlert(
        StockBuySetupAlert $alert,
        ?string $setupType = null,
        ?float $priorYearRevenue = null,
    ): int {
        return $this->scoreFromBreakdown($this->breakdown($alert, $setupType, $priorYearRevenue));
    }

    /**
     * @param  array<string, array{points: int|float, max: int}>  $breakdown
     */
    public function scoreFromBreakdown(array $breakdown): int
    {
        $max = (float) array_sum(array_column($breakdown, 'max'));
        if ($max <= 0.0) {
            return 0;
        }

        $raw = (float) array_sum(array_column($breakdown, 'points'));

        return max(0, min(100, (int) round(($raw / $max) * 100)));
    }

    /**
     * @param  array<string, array{points: int|float, max: int}>  $breakdown
     * @return array{raw: int|float, max: int, normalized: int}
     */
    public function scoreMetaFromBreakdown(array $breakdown): array
    {
        $rawFloat = (float) array_sum(array_column($breakdown, 'points'));
        $max = (int) array_sum(array_column($breakdown, 'max'));
        $raw = abs($rawFloat - round($rawFloat)) < 1e-9 ? (int) round($rawFloat) : round($rawFloat, 2);

        return [
            'raw' => $raw,
            'max' => $max,
            'normalized' => $max > 0 ? max(0, min(100, (int) round(($rawFloat / $max) * 100))) : 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function weights(?string $setupType = null): array
    {
        return app(BuySetupConfigService::class)->getScoreWeights($setupType);
    }

    private function spikeRarityPoints(StockBuySetupResult|StockBuySetupAlert $r, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        $points = (int) ($r->spikeRarityPoints ?? $r->spike_rarity_points ?? 0);

        return min($max, max(0, (int) round(($points / 7) * $max)));
    }

    private function baseDurationPoints(int $days, int $max): int
    {
        return match (true) {
            $max <= 0 => 0,
            $days >= 90 => $max,
            $days >= 60 => (int) round($max * 0.70),
            $days > 0 => (int) round($max * 0.30),
            default => 0,
        };
    }

    private function rangeCompressionPoints(float $pct, int $max): int
    {
        return match (true) {
            $max <= 0 => 0,
            $pct <= 10 => $max,
            $pct <= 18 => (int) round($max * 0.67),
            $pct <= 25 => (int) round($max * 0.33),
            default => 0,
        };
    }

    private function atrContractionPoints(float $ratio, int $max): int
    {
        return match (true) {
            $max <= 0 => 0,
            $ratio <= 0.60 => $max,
            $ratio <= 0.75 => (int) round($max * 0.70),
            $ratio <= 0.85 => (int) round($max * 0.40),
            default => 0,
        };
    }

    private function volumeDryUpPoints(float $score, int $max): int
    {
        return match (true) {
            $max <= 0 => 0,
            $score >= 0.30 => $max,
            $score >= 0.15 => (int) round($max * 0.60),
            $score > 0 => (int) round($max * 0.30),
            default => 0,
        };
    }

    private function breakoutDistancePoints(float $pct, int $max): int
    {
        // StockBuySetupScanner stores this as a signed value:
        //   positive = below the base high / breakout level
        //   negative = above the base high / already broken out
        //
        // Score actual proximity to the breakout level rather than allowing
        // every negative value to satisfy "$pct <= 2" and receive full points.
        $distance = abs($pct);

        return match (true) {
            $max <= 0 => 0,
            $distance <= 2 => $max,
            $distance <= 5 => (int) round($max * 0.70),
            $distance <= 10 => (int) round($max * 0.40),
            default => 0,
        };
    }

    /**
     * Scores the moving-average alignment string produced by every
     * detection algorithm (`StockBuySetupScanner`/`SharedDetectionHelpers::
     * maAlignmentString()`).
     *
     * For trend-following algorithms (Heartbeat, Range Compression
     * Breakout, Early Breakout Follow-Through) a bullish stack —
     * `50>150>200` with price above its 50-day average — is the ideal
     * signal, since these setups occur near/above prior highs.
     *
     * For reversal-style algorithms (Floor Reversal / Accumulation, see
     * BuySetupAlgorithmRegistry::isReversalStyle()) that same bullish stack
     * is, by design, backwards: the setup itself is a decline followed by
     * a base, so price legitimately sits *below* its 150/200-day averages.
     * This was previously "intentionally deferred" (see README_setup.md)
     * and is now scored on its own terms: the earliest, most direct
     * reversal signal — price reclaiming its 50-day average — earns full
     * points regardless of the longer-term stack, a `50>200` cross earns
     * half credit as an "improving but unconfirmed" signal, and a still-
     * declining alignment with price below its 50-day average earns none.
     */
    private function maAlignmentPoints(string $alignment, int $max, bool $reversalStyle = false): int
    {
        if ($max <= 0) {
            return 0;
        }

        if ($reversalStyle) {
            if (str_contains($alignment, 'price>50')) {
                return $max;
            }

            if (str_contains($alignment, '50>200')) {
                return (int) round($max * 0.50);
            }

            return 0;
        }

        if (str_contains($alignment, '50>150>200') && str_contains($alignment, 'price>50')) {
            return $max;
        }

        if (str_contains($alignment, '50>200')) {
            return (int) round($max * 0.50);
        }

        return 0;
    }

    private function relativeStrengthPoints(?float $rs, int $max): int
    {
        return match (true) {
            $max <= 0, $rs === null => 0,
            $rs >= 10 => $max,
            $rs >= 0 => (int) round($max * 0.50),
            default => 0,
        };
    }

    /**
     * Converts Operating Margin Expansion (basis points) into earned points
     * out of the configured maximum, via the reusable threshold
     * interpolation scorer. Missing data (bps === null) earns 0 points but
     * follows the existing missing-metric convention of leaving $max
     * unchanged (see relativeStrengthPoints / earnings_acceleration above).
     */
    private function operatingMarginExpansionPoints(?float $bps, int $max, ?string $setupType): int
    {
        if ($max <= 0) {
            return 0;
        }

        $thresholds = app(BuySetupConfigService::class)->getOperatingMarginExpansionThresholds($setupType);
        $normalized = $this->marginExpansionNormalizedScore($bps, $thresholds);

        return $normalized === null ? 0 : (int) round($max * ($normalized / 100));
    }

    private function operatingMarginExpansionValue(StockBuySetupResult|StockBuySetupAlert $r): string
    {
        $bps = $this->nullableFloat($r->operatingMarginExpansionBps ?? $r->operating_margin_expansion_bps ?? null);
        if ($bps === null) {
            return 'n/a';
        }

        // A zero score-facing expansion alongside improving negative margins
        // indicates the absolute TTM operating loss increased. Keep the reason
        // visible to avoid presenting this as a missing/miscomputed metric.
        $currentMargin = $this->nullableFloat($r->currentTtmOperatingMargin ?? $r->current_ttm_operating_margin ?? null);
        $priorMargin = $this->nullableFloat($r->priorTtmOperatingMargin ?? $r->prior_ttm_operating_margin ?? null);
        if ($bps == 0.0 && $currentMargin !== null && $priorMargin !== null && $currentMargin < 0 && $currentMargin > $priorMargin) {
            $rawBps = (int) round(($currentMargin - $priorMargin) * 10000);

            return '+'.number_format($rawBps).' bps (0 scored: operating loss increased)';
        }

        $sign = $bps >= 0 ? '+' : '';

        return $sign.number_format($bps).' bps';
    }

    /**
     * Converts FCF Margin Expansion (basis points) into earned points out
     * of the configured maximum. Mirrors operatingMarginExpansionPoints().
     */
    private function fcfMarginExpansionPoints(?float $bps, int $max, ?string $setupType): int
    {
        if ($max <= 0) {
            return 0;
        }

        $thresholds = app(BuySetupConfigService::class)->getFcfMarginExpansionThresholds($setupType);
        $normalized = $this->marginExpansionNormalizedScore($bps, $thresholds);

        return $normalized === null ? 0 : (int) round($max * ($normalized / 100));
    }

    private function fcfMarginExpansionValue(StockBuySetupResult|StockBuySetupAlert $r): string
    {
        $bps = $this->nullableFloat($r->fcfMarginExpansionBps ?? $r->fcf_margin_expansion_bps ?? null);
        if ($bps === null) {
            return 'n/a';
        }

        $currentMargin = $this->nullableFloat($r->currentTtmFcfMargin ?? $r->current_ttm_fcf_margin ?? null);
        $priorMargin = $this->nullableFloat($r->priorTtmFcfMargin ?? $r->prior_ttm_fcf_margin ?? null);
        if ($bps == 0.0 && $currentMargin !== null && $priorMargin !== null && $currentMargin < 0 && $currentMargin > $priorMargin) {
            $rawBps = (int) round(($currentMargin - $priorMargin) * 10000);

            return '+'.number_format($rawBps).' bps (0 scored: cash burn increased)';
        }

        $sign = $bps >= 0 ? '+' : '';

        return $sign.number_format($bps).' bps';
    }

    /**
     * Converts a margin-expansion basis-point value into a 0-100 normalized
     * score via the reusable threshold interpolation scorer. Missing data
     * (bps === null) returns null rather than a valid 0 score, so callers
     * can distinguish "no expansion" from "unavailable".
     *
     * @param  array{threshold_25: int, threshold_50: int, threshold_75: int, threshold_100: int}  $thresholds
     */
    private function marginExpansionNormalizedScore(?float $bps, array $thresholds): ?float
    {
        if ($bps === null) {
            return null;
        }

        return app(ThresholdInterpolationScorer::class)->score(
            $bps,
            (float) $thresholds['threshold_25'],
            (float) $thresholds['threshold_50'],
            (float) $thresholds['threshold_75'],
            (float) $thresholds['threshold_100'],
        );
    }

    /**
     * @return array{earnings_acceleration: float, sales_acceleration: float}
     */
    private function accelerationScales(): array
    {
        $configured = (array) config('market_data.buy_setup_scanner.acceleration_scales', []);

        return [
            'earnings_acceleration' => max(0.0001, (float) ($configured['earnings_acceleration'] ?? 75)),
            'sales_acceleration' => max(0.0001, (float) ($configured['sales_acceleration'] ?? 3000)),
        ];
    }

    /**
     * Scores positive acceleration on a smooth logarithmic curve.
     *
     * The configured scale is the positive acceleration value that maps to
     * the component's full weight. Scoring uses true logarithmic
     * normalization: log(1 + value) / log(1 + scale). This preserves a
     * meaningful distinction between exceptional values (for example, 104
     * versus 2,715 sales-acceleration points) while compressing extreme
     * outliers. Values at or above the configured scale are capped at the
     * component maximum.
     */
    private function logarithmicBonusPoints(?float $value, int $max, float $scale): int
    {
        if ($max <= 0) {
            return 0;
        }

        $normalized = $this->logarithmicNormalizedScore($value, $scale);

        return $normalized === null ? 0 : (int) round($max * ($normalized / 100));
    }

    /**
     * Converts a raw acceleration value into a 0-100 normalized score using
     * the same true-logarithmic curve as logarithmicBonusPoints(), but
     * independent of any setup type's configured weight. Missing data
     * (value === null) returns null rather than a valid 0 score.
     */
    private function logarithmicNormalizedScore(?float $value, float $scale): ?float
    {
        if ($value === null) {
            return null;
        }
        if ($value <= 0) {
            return 0.0;
        }

        $normalized = log1p($value) / log1p(max(0.0001, $scale));

        return round(100 * min(1.0, max(0.0, $normalized)), 4);
    }

    /**
     * Calculates the minimum raw sales acceleration required to achieve the given normalized score.
     */
    public function minSalesAccelerationForScore(float $targetScore): float
    {
        if ($targetScore <= 0.0) {
            return 0.0;
        }

        $scales = $this->accelerationScales();
        $scale = $scales['sales_acceleration'];
        $targetRatio = max(0.0, min(1.0, ($targetScore - 0.0001) / 100.0));

        return max(0.0, pow(1.0 + $scale, $targetRatio) - 1.0);
    }

    /**
     * Calculates the minimum raw operating margin expansion (in bps) required to achieve the given normalized score.
     */
    public function minOperatingMarginExpansionForScore(float $targetScore, ?string $setupType = null): float
    {
        if ($targetScore <= 0.0) {
            return 0.0;
        }

        $thresholds = app(BuySetupConfigService::class)->getOperatingMarginExpansionThresholds($setupType);
        $t25 = (float) $thresholds['threshold_25'];
        $t50 = (float) $thresholds['threshold_50'];
        $t75 = (float) $thresholds['threshold_75'];
        $t100 = (float) $thresholds['threshold_100'];

        return match (true) {
            $targetScore <= 25.0 => $t25 * ($targetScore / 25.0),
            $targetScore <= 50.0 => $t25 + ($t50 - $t25) * (($targetScore - 25.0) / 25.0),
            $targetScore <= 75.0 => $t50 + ($t75 - $t50) * (($targetScore - 50.0) / 25.0),
            $targetScore <= 100.0 => $t75 + ($t100 - $t75) * (($targetScore - 75.0) / 25.0),
            default => $t100,
        };
    }

    /**
     * Reduce the final earned sales-acceleration points when the YoY
     * comparison is based on a very small prior-year revenue denominator.
     *
     * The penalty is applied to the earned component points after logarithmic
     * scoring, so it scales automatically with each setup type's configurable
     * sales_acceleration weight.
     *
     * @param  array<int, array{threshold: float|int, penalty_pct: float|int}>|null  $penalties
     */
    public function applyPriorYearRevenuePenalty(
        int $points,
        ?float $priorYearRevenue,
        ?string $setupType = null,
        ?array $penalties = null,
    ): int {
        if ($points <= 0 || $priorYearRevenue === null) {
            return $points;
        }

        $tiers = $penalties ?? app(BuySetupConfigService::class)->getPriorYearRevenuePenalties($setupType);

        if (empty($tiers)) {
            return $points;
        }

        // Sort ascending by threshold to ensure lowest threshold is checked first
        usort($tiers, fn ($a, $b) => ((float) ($a['threshold'] ?? 0)) <=> ((float) ($b['threshold'] ?? 0)));

        foreach ($tiers as $tier) {
            $threshold = (float) ($tier['threshold'] ?? 0);
            $penaltyPct = (float) ($tier['penalty_pct'] ?? 0);

            if ($priorYearRevenue < $threshold) {
                $multiplier = max(0.0, 1.0 - ($penaltyPct / 100.0));

                return max(0, (int) round($points * $multiplier));
            }
        }

        return $points;
    }

    /**
     * Growth Synergy Bonus (v1): a small additional bonus, on top of the
     * normal setup score, rewarding companies where Sales Acceleration,
     * Operating Margin Expansion, and FCF Margin Expansion all confirm
     * strong growth quality at the same time.
     *
     * Reuses the already-calculated normalized (0-100) scores for those
     * three metrics — nothing is recalculated. Only eligible when Sales
     * YoY meets the configured minimum, which prevents shrinking-revenue
     * companies from earning a growth bonus via cost-cutting margin gains.
     * Disabled by default per setup type; the caller is responsible for
     * adding the returned points on top of the base setup score and
     * respecting the application's overall score cap.
     *
     * @return array{enabled: bool, eligible: bool, points: int, max: int, sales_yoy_pct: float|null, sales_acceleration_score: float|null, operating_margin_expansion_score: float|null, fcf_margin_expansion_score: float|null}
     */
    public function growthSynergyBonus(
        StockBuySetupResult|StockBuySetupAlert $r,
        ?string $setupType = null,
    ): array {
        $configService = app(BuySetupConfigService::class);
        $config = $configService->getGrowthSynergyBonusConfig($setupType);
        $maxPoints = $config['max_points'];

        $salesYoy = $this->nullableFloat($r->quarterlyRevenueGrowthPct ?? $r->quarterly_revenue_growth_pct ?? null);
        $scales = $this->accelerationScales();
        $salesAccelerationScore = $this->logarithmicNormalizedScore(
            $this->nullableFloat($r->salesAcceleration ?? $r->sales_acceleration ?? null),
            $scales['sales_acceleration'],
        );
        $operatingMarginExpansionScore = $this->marginExpansionNormalizedScore(
            $this->nullableFloat($r->operatingMarginExpansionBps ?? $r->operating_margin_expansion_bps ?? null),
            $configService->getOperatingMarginExpansionThresholds($setupType),
        );
        $fcfMarginExpansionScore = $this->marginExpansionNormalizedScore(
            $this->nullableFloat($r->fcfMarginExpansionBps ?? $r->fcf_margin_expansion_bps ?? null),
            $configService->getFcfMarginExpansionThresholds($setupType),
        );

        $result = [
            'enabled' => $config['enabled'],
            'eligible' => false,
            'points' => 0,
            'max' => $maxPoints,
            'sales_yoy_pct' => $salesYoy,
            'sales_acceleration_score' => $salesAccelerationScore,
            'operating_margin_expansion_score' => $operatingMarginExpansionScore,
            'fcf_margin_expansion_score' => $fcfMarginExpansionScore,
        ];

        if (! $config['enabled'] || $maxPoints <= 0) {
            return $result;
        }

        // Eligibility gate: shrinking/insufficient revenue growth never earns
        // a growth synergy bonus, regardless of how strong the margins are.
        if ($salesYoy === null || $salesYoy < $config['min_sales_yoy']) {
            return $result;
        }

        $result['eligible'] = true;

        $meetsAll = function (float $threshold) use ($salesAccelerationScore, $operatingMarginExpansionScore, $fcfMarginExpansionScore): bool {
            return $salesAccelerationScore !== null && $salesAccelerationScore >= $threshold
                && $operatingMarginExpansionScore !== null && $operatingMarginExpansionScore >= $threshold
                && $fcfMarginExpansionScore !== null && $fcfMarginExpansionScore >= $threshold;
        };

        $twoMetricConfirmation = $salesAccelerationScore !== null && $salesAccelerationScore >= $config['medium_threshold']
            && $operatingMarginExpansionScore !== null && $operatingMarginExpansionScore >= $config['medium_threshold'];

        $points = match (true) {
            $meetsAll($config['exceptional_threshold']) => 10,
            $meetsAll($config['strong_threshold']) => 8,
            $meetsAll($config['medium_threshold']) => 5,
            $twoMetricConfirmation => 2,
            default => 0,
        };

        $result['points'] = max(0, min($maxPoints, $points));

        return $result;
    }

    /**
     * Builds a display-only score breakdown entry for the Growth Synergy
     * Bonus, in the same {label, points, max, value} shape as breakdown().
     * Deliberately NOT part of breakdown()'s weighted-component pool —
     * scoreFromBreakdown()/scoreMetaFromBreakdown() sum every entry's
     * points/max to normalize to 0-100, whereas the synergy bonus must be
     * added flat, after that normalization (see growthSynergyBonus()).
     *
     * @return array{label: string, points: int, max: int, value: string}
     */
    public function growthSynergyBonusBreakdownEntry(
        StockBuySetupResult|StockBuySetupAlert $r,
        ?string $setupType = null,
    ): array {
        $bonus = $this->growthSynergyBonus($r, $setupType);

        $format = fn (?float $v) => $v === null ? 'n/a' : number_format($v, 0);

        $value = sprintf(
            'Sales YoY: %s%% | Sales Accel: %s | Operating Margin Expansion: %s | FCF Margin Expansion: %s',
            $bonus['sales_yoy_pct'] === null ? 'n/a' : number_format($bonus['sales_yoy_pct'], 0),
            $format($bonus['sales_acceleration_score']),
            $format($bonus['operating_margin_expansion_score']),
            $format($bonus['fcf_margin_expansion_score']),
        );

        return [
            'label' => 'Growth synergy bonus',
            'points' => $bonus['points'],
            'max' => $bonus['max'],
            'value' => $value,
        ];
    }

    /**
     * Ignition / Early Accumulation Bonus (v1 + freshness guard): a flat
     * configurable bonus added on top of the normalized setup score (disabled
     * by default per setup type when bonus_points = 0).
     *
     * Rewards setups that have spent a meaningful period in a base with
     * meaningful volume dry-up, and suddenly experience abnormal relative
     * volume accompanied by a meaningful positive spike-day price move.
     *
     * Two confirmation paths:
     * 1. Price-led: moderately abnormal RVOL + very strong price expansion
     * 2. Volume-led: strongly abnormal RVOL + somewhat lower price expansion
     *
     * Freshness layer:
     * The bonus progressively decays as the stock advances post-ignition.
     * Peak post-ignition appreciation <= full_bonus_max_post_gain_pct receives
     * 100% of bonus points, decaying linearly to 0% at zero_bonus_post_gain_pct.
     *
     * @return array{
     *     bonus_points: int,
     *     points: int,
     *     max: int,
     *     eligible: bool,
     *     quality_qualified: bool,
     *     freshness_multiplier: float,
     *     freshness_eligible: bool,
     *     post_ignition_gain_pct: float|null,
     *     post_ignition_peak_gain_pct: float|null,
     *     base_duration_days: int|null,
     *     volume_dry_up_pct: float|null,
     *     spike_relative_volume: float|null,
     *     spike_price_change_pct: float|null,
     *     price_led: bool,
     *     volume_led: bool,
     *     price_led_qualified: bool,
     *     volume_led_qualified: bool
     * }
     */
    public function ignitionBonus(
        StockBuySetupResult|StockBuySetupAlert $r,
        ?string $setupType = null,
    ): array {
        $type = $setupType ?? ($r->setupType ?? $r->setup_type ?? null);
        $configService = app(BuySetupConfigService::class);
        $config = $configService->getIgnitionBonusConfig($type);
        $bonusPoints = $config['bonus_points'];

        $baseDays = $this->nullableInt($r->baseDurationDays ?? $r->base_duration_days ?? null);
        $dryUpScore = $this->nullableFloat($r->volumeDryUpScore ?? $r->volume_dry_up_score ?? null);
        $volumeDryUpPct = $dryUpScore !== null ? $dryUpScore * 100.0 : null;
        $rvol = $this->nullableFloat($r->spikeRelativeVolume ?? $r->spike_relative_volume ?? null);
        $priceChangePct = $this->nullableFloat($r->spikePriceChangePct ?? $r->spike_price_change_pct ?? null);

        $currentGainPct = $this->nullableFloat(
            $r->postIgnitionGainPct
                ?? $r->post_ignition_gain_pct
                ?? null
        );
        $peakGainPct = $this->nullableFloat(
            $r->postIgnitionPeakGainPct
                ?? $r->post_ignition_peak_gain_pct
                ?? null
        );

        $hasAllInputs = $baseDays !== null
            && $dryUpScore !== null
            && $rvol !== null
            && $priceChangePct !== null;

        $dormant = $hasAllInputs
            && $baseDays >= $config['min_base_days']
            && $volumeDryUpPct >= $config['min_volume_dry_up_pct'];

        $priceLed = $hasAllInputs
            && $rvol >= $config['price_led_min_relative_volume']
            && $priceChangePct >= $config['price_led_min_price_change_pct'];

        $volumeLed = $hasAllInputs
            && $rvol >= $config['volume_led_min_relative_volume']
            && $priceChangePct >= $config['volume_led_min_price_change_pct'];

        $priceLedQualified = $bonusPoints > 0 && $dormant && $priceLed;
        $volumeLedQualified = $bonusPoints > 0 && $dormant && $volumeLed;
        $qualityQualified = $priceLedQualified || $volumeLedQualified;

        $fullBonusThrough = (float) $config['full_bonus_max_post_gain_pct'];
        $zeroBonusAt = (float) $config['zero_bonus_post_gain_pct'];

        if ($peakGainPct !== null && $zeroBonusAt > $fullBonusThrough) {
            if ($peakGainPct <= $fullBonusThrough) {
                $freshnessMultiplier = 1.0;
            } elseif ($peakGainPct >= $zeroBonusAt) {
                $freshnessMultiplier = 0.0;
            } else {
                $freshnessMultiplier = ($zeroBonusAt - $peakGainPct) / ($zeroBonusAt - $fullBonusThrough);
            }
            $freshnessMultiplier = max(0.0, min(1.0, $freshnessMultiplier));
        } else {
            $freshnessMultiplier = 0.0;
        }

        $freshnessEligible = $freshnessMultiplier > 0.0;
        $eligible = $qualityQualified && $freshnessEligible;
        $points = $qualityQualified ? (int) round($bonusPoints * $freshnessMultiplier) : 0;

        return [
            'bonus_points' => $bonusPoints,
            'points' => $points,
            'max' => $bonusPoints,
            'eligible' => $eligible,
            'quality_qualified' => $qualityQualified,
            'freshness_multiplier' => $freshnessMultiplier,
            'freshness_eligible' => $freshnessEligible,
            'post_ignition_gain_pct' => $currentGainPct,
            'post_ignition_peak_gain_pct' => $peakGainPct,
            'base_duration_days' => $baseDays,
            'volume_dry_up_pct' => $volumeDryUpPct,
            'spike_relative_volume' => $rvol,
            'spike_price_change_pct' => $priceChangePct,
            'price_led' => $priceLed,
            'volume_led' => $volumeLed,
            'price_led_qualified' => $priceLedQualified,
            'volume_led_qualified' => $volumeLedQualified,
        ];
    }

    /**
     * Builds a display-only score breakdown entry for the Ignition / Early
     * Accumulation Bonus, in the same {label, points, max, value} shape as
     * breakdown(). Deliberately NOT part of breakdown()'s weighted pool.
     *
     * @return array{label: string, points: int, max: int, value: string}
     */
    public function ignitionBonusBreakdownEntry(
        StockBuySetupResult|StockBuySetupAlert $r,
        ?string $setupType = null,
    ): array {
        $bonus = $this->ignitionBonus($r, $setupType);

        $baseText = $bonus['base_duration_days'] !== null ? "{$bonus['base_duration_days']}d base" : 'n/a base';
        $dryUpText = $bonus['volume_dry_up_pct'] !== null ? number_format($bonus['volume_dry_up_pct'], 0).'% dry-up' : 'n/a dry-up';
        $rvolText = $bonus['spike_relative_volume'] !== null ? number_format($bonus['spike_relative_volume'], 1).'x relative volume' : 'n/a relative volume';
        $priceText = $bonus['spike_price_change_pct'] !== null
            ? ($bonus['spike_price_change_pct'] >= 0 ? '+' : '').number_format($bonus['spike_price_change_pct'], 1).'%'
            : 'n/a price';

        $stats = sprintf('%s | %s | %s | %s', $baseText, $dryUpText, $rvolText, $priceText);

        $pathText = match (true) {
            $bonus['price_led_qualified'] && $bonus['volume_led_qualified'] => 'Price + volume ignition',
            $bonus['volume_led_qualified'] => 'Volume-led ignition',
            $bonus['price_led_qualified'] => 'Price-led ignition',
            default => null,
        };

        if ($pathText !== null && ! $bonus['freshness_eligible'] && $bonus['post_ignition_peak_gain_pct'] !== null) {
            $pathText .= ' — late/extended';
        }

        $lines = [];
        if ($pathText !== null) {
            $lines[] = $pathText;
        }
        $lines[] = $stats;

        if ($bonus['post_ignition_gain_pct'] !== null || $bonus['post_ignition_peak_gain_pct'] !== null) {
            $currText = $bonus['post_ignition_gain_pct'] !== null
                ? ($bonus['post_ignition_gain_pct'] >= 0 ? '+' : '').number_format($bonus['post_ignition_gain_pct'], 1).'%'
                : 'n/a';
            $peakText = $bonus['post_ignition_peak_gain_pct'] !== null
                ? ($bonus['post_ignition_peak_gain_pct'] >= 0 ? '+' : '').number_format($bonus['post_ignition_peak_gain_pct'], 1).'%'
                : 'n/a';
            $freshnessText = $bonus['freshness_multiplier'] > 0
                ? sprintf('freshness %d%%', (int) round($bonus['freshness_multiplier'] * 100))
                : 'bonus expired';

            $lines[] = sprintf('Post-ignition: %s current | %s peak | %s', $currText, $peakText, $freshnessText);
        } elseif ($bonus['quality_qualified']) {
            $lines[] = 'Post-ignition freshness unavailable | bonus withheld';
        }

        return [
            'label' => 'Ignition / early accumulation',
            'points' => $bonus['points'],
            'max' => $bonus['max'],
            'value' => implode("\n", $lines),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (int) $value;
    }

    /**
     * Calculate the logarithmic Book Value score fraction in [0, 1].
     *
     * Formula:
     *   distance       = ln(r / A) / ln(E / A)
     *   score_fraction = clamp(1 - (0.25 * distance), 0, 1)
     *
     * Returns null when P/B is missing, non-positive, or non-finite.
     */
    public function bookValueScoreFraction(
        ?float $priceToBook,
        ?string $setupType = null,
        ?float $amazingThreshold = null,
        ?float $exceptionalThreshold = null,
    ): ?float {
        if (
            $priceToBook === null
            || ! is_finite($priceToBook)
            || $priceToBook < StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO
            || round($priceToBook, 2) <= 0.0
        ) {
            return null;
        }

        if ($amazingThreshold === null || $exceptionalThreshold === null) {
            $thresholds = app(BuySetupConfigService::class)->getBookValueThresholds($setupType);
            $amazingThreshold ??= (float) $thresholds['AMAZING_BOOK_VALUE'];
            $exceptionalThreshold ??= (float) $thresholds['EXCEPTIONAL_BOOK_VALUE'];
        }

        if (
            ! is_finite($amazingThreshold)
            || ! is_finite($exceptionalThreshold)
            || $amazingThreshold <= 0.0
            || $exceptionalThreshold <= $amazingThreshold
        ) {
            return null;
        }

        if ($priceToBook <= $amazingThreshold) {
            return 1.0;
        }

        $denom = log($exceptionalThreshold / $amazingThreshold);
        if (! is_finite($denom) || $denom <= 0.0) {
            return null;
        }

        $distance = log($priceToBook / $amazingThreshold) / $denom;
        if (! is_finite($distance)) {
            return null;
        }

        return max(0.0, min(1.0, 1.0 - (0.25 * $distance)));
    }

    /**
     * Calculate weighted Book Value points using full internal precision.
     *
     * Formula:
     *   book_value_points = W * score_fraction
     */
    public function bookValuePoints(
        ?float $priceToBook,
        ?int $max = null,
        ?string $setupType = null,
        ?float $amazingThreshold = null,
        ?float $exceptionalThreshold = null,
    ): int|float {
        $weight = $max ?? ($this->weights($setupType)['book_value'] ?? 0);
        if ($weight <= 0) {
            return 0;
        }

        $fraction = $this->bookValueScoreFraction($priceToBook, $setupType, $amazingThreshold, $exceptionalThreshold);
        if ($fraction === null) {
            return 0;
        }

        $points = max(0.0, min((float) $weight, $weight * $fraction));

        return abs($points - round($points)) < 1e-9 ? (int) round($points) : $points;
    }

    /**
     * Upper zero-score P/B ratio threshold derived from the two anchors:
     *   zero_score_ratio = A * (E / A)^4
     */
    public function bookValueZeroScoreRatio(
        ?string $setupType = null,
        ?float $amazingThreshold = null,
        ?float $exceptionalThreshold = null,
    ): float {
        if ($amazingThreshold === null || $exceptionalThreshold === null) {
            $thresholds = app(BuySetupConfigService::class)->getBookValueThresholds($setupType);
            $amazingThreshold ??= (float) $thresholds['AMAZING_BOOK_VALUE'];
            $exceptionalThreshold ??= (float) $thresholds['EXCEPTIONAL_BOOK_VALUE'];
        }

        if ($amazingThreshold <= 0.0 || $exceptionalThreshold <= $amazingThreshold) {
            $amazingThreshold = BuySetupConfigService::AMAZING_BOOK_VALUE;
            $exceptionalThreshold = BuySetupConfigService::EXCEPTIONAL_BOOK_VALUE;
        }

        return $amazingThreshold * pow($exceptionalThreshold / $amazingThreshold, 4);
    }

    /**
     * Extract or compute a valid positive Price-to-Book ratio from a setup result or alert.
     * Returns null (N/A) when book value is missing, zero, or negative, or when P/B <= 0.
     */
    public function extractPriceToBook(StockBuySetupResult|StockBuySetupAlert $r): ?float
    {
        $equity = $this->nullableFloat($r->stockholdersEquity ?? $r->stockholders_equity ?? null);
        if ($equity !== null && (! is_finite($equity) || $equity <= 0.0)) {
            return null;
        }

        $bvps = $this->nullableFloat($r->bookValuePerShare ?? $r->book_value_per_share ?? null);
        if ($bvps !== null && (! is_finite($bvps) || $bvps <= 0.0)) {
            return null;
        }

        // If price_to_book_ratio was explicitly set to a non-numeric, zero, negative,
        // or sub-0.01 value on an alert attribute or result property, treat as N/A
        // and never fall through to recompute price / bvps.
        if ($r instanceof StockBuySetupAlert) {
            $rawAttr = $r->getAttributes()['price_to_book_ratio'] ?? null;
            if ($rawAttr !== null && $rawAttr !== '') {
                if (! is_numeric($rawAttr)) {
                    return null;
                }
                $attrFloat = (float) $rawAttr;
                if (! is_finite($attrFloat) || $attrFloat < StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO || round($attrFloat, 2) <= 0.0) {
                    return null;
                }
            }
        }

        $rawPbProp = $r->priceToBookRatio
            ?? $r->price_to_book_ratio
            ?? $r->pbRatio
            ?? $r->pb_ratio
            ?? null;
        if ($rawPbProp !== null && $rawPbProp !== '') {
            $pb = $this->nullableFloat($rawPbProp);

            return ($pb !== null && is_finite($pb) && $pb >= StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO && round($pb, 2) > 0.0)
                ? $pb
                : null;
        }

        $price = $this->nullableFloat($r->price ?? null);
        if ($bvps === null && $equity !== null && $equity > 0.0) {
            $shares = $this->nullableInt($r->sharesOutstanding ?? $r->shares_outstanding ?? null);
            if ($shares !== null && $shares > 0) {
                $bvps = $equity / $shares;
            }
        }

        if ($price !== null && is_finite($price) && $price > 0.0 && $bvps !== null && is_finite($bvps) && $bvps >= 0.0001) {
            $computed = $price / $bvps;

            return (is_finite($computed) && $computed >= StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO && round($computed, 2) > 0.0)
                ? $computed
                : null;
        }

        return null;
    }

    /**
     * Build the breakdown entry for the Book Value component.
     *
     * Missing, zero, or negative book value is treated as N/A and excluded from
     * the scoring denominator (`max = 0`), while retaining `configured_max` and
     * `available = false` for score coverage. A valid expensive P/B (e.g. 4.00)
     * remains in the denominator (`max = $configuredWeight`) with `points = 0`.
     *
     * @return array{
     *     label: string,
     *     points: int|float,
     *     max: int,
     *     configured_max: int,
     *     available: bool,
     *     pb_ratio: float|null,
     *     score_fraction: float|null,
     *     score_pct: float|null,
     *     value: string
     * }
     */
    private function bookValueBreakdownEntry(
        StockBuySetupResult|StockBuySetupAlert $r,
        ?string $setupType,
        int $configuredWeight,
    ): array {
        $configuredWeight = max(0, $configuredWeight);
        $pbRatio = $this->extractPriceToBook($r);

        if ($pbRatio === null) {
            return [
                'label' => 'Book Value',
                'points' => 0,
                'max' => 0,
                'configured_max' => $configuredWeight,
                'available' => false,
                'pb_ratio' => null,
                'score_fraction' => null,
                'score_pct' => null,
                'value' => 'N/A (book value unavailable)',
            ];
        }

        $scoreFraction = $this->bookValueScoreFraction($pbRatio, $setupType) ?? 0.0;
        $scorePct = round($scoreFraction * 100.0, 2);

        if ($configuredWeight <= 0) {
            $points = 0;
            $effectiveMax = 0;
        } else {
            $rawPoints = max(0.0, min((float) $configuredWeight, $configuredWeight * $scoreFraction));
            $points = abs($rawPoints - round($rawPoints)) < 1e-9 ? (int) round($rawPoints) : $rawPoints;
            $effectiveMax = $configuredWeight;
        }

        $displayPoints = number_format((float) $points, 2);
        $displayPb = number_format($pbRatio, 2);
        $displayScorePct = number_format($scoreFraction * 100.0, 2);

        if ($scoreFraction <= 0.0) {
            $value = sprintf(
                "%s / %d (P/B %s)\nP/B Ratio: %s\nComponent Score: %s%%\nWeighted Points: %s / %d",
                $displayPoints,
                $configuredWeight,
                $displayPb,
                $displayPb,
                $displayScorePct,
                $displayPoints,
                $configuredWeight,
            );
        } else {
            $value = sprintf(
                "P/B Ratio: %s\nComponent Score: %s%%\nWeighted Points: %s / %d",
                $displayPb,
                $displayScorePct,
                $displayPoints,
                $configuredWeight,
            );
        }

        return [
            'label' => 'Book Value',
            'points' => $points,
            'max' => $effectiveMax,
            'configured_max' => $configuredWeight,
            'available' => true,
            'pb_ratio' => $pbRatio,
            'score_fraction' => $scoreFraction,
            'score_pct' => $scorePct,
            'value' => $value,
        ];
    }

    /**
     * Idempotently recalculate existing persisted StockBuySetupAlert rows to
     * establish a new scoring baseline (e.g., when introducing Book Value or
     * migrating configurations) without generating artificial setup
     * improvement/drop notifications.
     *
     * Sets `previous_setup_score = setup_score` and `score_delta = 0` on
     * recalculated alerts so digest builders treat them as unchanged at the
     * new baseline, while subsequent genuine score changes continue to
     * trigger normal events and notification digests.
     */
    public function recalculateHistoricalBaseline(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('stock_buy_setup_alerts')) {
            return 0;
        }

        StockBuySetupAlert::ensureBookValueSchema();
        app(BuySetupConfigService::class)->ensureBookValueConfigMigrated();

        $analyzer = app(StockFundamentalsAnalyzer::class);
        $hasFinancialsTable = \Illuminate\Support\Facades\Schema::hasTable('company_financials');
        $hasProfilesTable = \Illuminate\Support\Facades\Schema::hasTable('company_profiles');
        $hasPayloadsTable = \Illuminate\Support\Facades\Schema::hasTable('market_data_payloads');
        $hasBookValueCol = \Illuminate\Support\Facades\Schema::hasColumn('stock_buy_setup_alerts', 'book_value_per_share');
        $hasPbCol = \Illuminate\Support\Facades\Schema::hasColumn('stock_buy_setup_alerts', 'price_to_book_ratio');
        $hasEquityCol = \Illuminate\Support\Facades\Schema::hasColumn('stock_buy_setup_alerts', 'stockholders_equity');
        $hasPrevScoreCol = StockBuySetupAlert::hasRunTrackingColumn('previous_setup_score');
        $hasScoreDeltaCol = StockBuySetupAlert::hasRunTrackingColumn('score_delta');

        $affectedRuns = [];
        $updatedCount = 0;

        StockBuySetupAlert::query()->chunkById(200, function ($alerts) use (
            $analyzer,
            $hasFinancialsTable,
            $hasProfilesTable,
            $hasPayloadsTable,
            $hasBookValueCol,
            $hasPbCol,
            $hasEquityCol,
            $hasPrevScoreCol,
            $hasScoreDeltaCol,
            &$affectedRuns,
            &$updatedCount,
        ) {
            foreach ($alerts as $alert) {
                $symbol = strtoupper(trim((string) $alert->symbol));

                $profile = ($hasProfilesTable && $symbol !== '')
                    ? \App\Models\CompanyProfile::query()->where('symbol', $symbol)->first()
                    : null;

                $alertPrice = ($alert->price !== null && (float) $alert->price > 0.0) ? (float) $alert->price : null;
                $profilePrice = ($profile?->price !== null && (float) $profile->price > 0.0) ? (float) $profile->price : null;
                $price = $alertPrice ?? $profilePrice;

                $alertMarketCap = $alert->getAttributes()['market_cap'] ?? null;
                $profileMarketCap = $profile?->market_cap ?? null;

                $shares = ($alert->shares_outstanding !== null && (int) $alert->shares_outstanding > 0)
                    ? (int) $alert->shares_outstanding
                    : (($profile?->shares_outstanding !== null && (int) $profile->shares_outstanding > 0) ? (int) $profile->shares_outstanding : null);

                if ($shares === null) {
                    $derivedShares = null;
                    if ($alertMarketCap !== null && (float) $alertMarketCap > 0.0 && $alertPrice !== null && $alertPrice > 0.0) {
                        $derivedShares = (int) round((float) $alertMarketCap / $alertPrice);
                    } elseif ($profileMarketCap !== null && (float) $profileMarketCap > 0.0 && $profilePrice !== null && $profilePrice > 0.0) {
                        $derivedShares = (int) round((float) $profileMarketCap / $profilePrice);
                    }
                    if ($derivedShares !== null && $derivedShares > 0) {
                        $shares = $derivedShares;
                        if ($alert->shares_outstanding === null) {
                            $alert->shares_outstanding = $derivedShares;
                        }
                    }
                }

                $currency = ($profile && is_string($profile->currency) && trim($profile->currency) !== '')
                    ? strtoupper(trim($profile->currency))
                    : null;
                if ($currency === null && $hasPayloadsTable && $symbol !== '') {
                    $payloadRow = \App\Models\MarketDataPayload::query()
                        ->where('symbol', $symbol)
                        ->where('data_type', 'profile')
                        ->orderByDesc('retrieved_at')
                        ->first();
                    if ($payloadRow && is_array($payloadRow->payload) && ! empty($payloadRow->payload['currency'])) {
                        $currency = strtoupper(trim((string) $payloadRow->payload['currency']));
                    }
                }
                if ($currency === null) {
                    $currency = $this->inferAlertTradingCurrency($alert->exchange ?: $profile?->exchange, $symbol);
                }

                $balanceRows = [];
                $incomeRows = [];
                if ($hasFinancialsTable && $symbol !== '') {
                    $balanceRows = \App\Models\CompanyFinancial::query()
                        ->where('symbol', $symbol)
                        ->where('statement_type', \App\Models\CompanyFinancial::TYPE_BALANCE_SHEET)
                        ->orderByDesc('fiscal_date')
                        ->limit(8)
                        ->get()
                        ->map(fn (\App\Models\CompanyFinancial $f) => [
                            'date' => $f->fiscal_date->toDateString(),
                            'stockholders_equity' => $f->stockholders_equity !== null ? (float) $f->stockholders_equity : null,
                            'fiscal_year' => $f->fiscal_year ?? (isset($f->raw_metrics['fiscalYear']) && is_numeric($f->raw_metrics['fiscalYear']) ? (int) $f->raw_metrics['fiscalYear'] : null),
                            'period' => $f->period !== 'quarter' ? $f->period : ($f->raw_metrics['period'] ?? $f->period),
                            'filing_date' => $f->raw_metrics['fillingDate'] ?? $f->raw_metrics['filingDate'] ?? null,
                            'reported_currency' => $f->reported_currency ?? ($f->raw_metrics['reportedCurrency'] ?? null),
                            'raw' => $f->raw_metrics ?? [],
                        ])
                        ->all();

                    $incomeRows = \App\Models\CompanyFinancial::query()
                        ->where('symbol', $symbol)
                        ->where('statement_type', \App\Models\CompanyFinancial::TYPE_INCOME)
                        ->orderByDesc('fiscal_date')
                        ->limit(8)
                        ->get()
                        ->map(fn (\App\Models\CompanyFinancial $f) => [
                            'date' => $f->fiscal_date->toDateString(),
                            'revenue' => $f->revenue !== null ? (float) $f->revenue : null,
                            'net_income' => $f->net_income !== null ? (float) $f->net_income : null,
                            'eps' => $f->eps !== null ? (float) $f->eps : null,
                            'fiscal_year' => $f->fiscal_year ?? (isset($f->raw_metrics['fiscalYear']) && is_numeric($f->raw_metrics['fiscalYear']) ? (int) $f->raw_metrics['fiscalYear'] : null),
                            'period' => $f->period !== 'quarter' ? $f->period : ($f->raw_metrics['period'] ?? $f->period),
                            'filing_date' => $f->raw_metrics['fillingDate'] ?? $f->raw_metrics['filingDate'] ?? null,
                            'reported_currency' => $f->reported_currency ?? ($f->raw_metrics['reportedCurrency'] ?? null),
                            'raw' => $f->raw_metrics ?? [],
                        ])
                        ->all();
                }

                if (! empty($balanceRows)) {
                    $bvMetrics = $analyzer->bookValueMetrics($balanceRows, $incomeRows, $price, $shares, $currency);
                    if ($hasEquityCol) {
                        $alert->stockholders_equity = $bvMetrics['stockholders_equity'];
                    }
                    if ($hasBookValueCol) {
                        $alert->book_value_per_share = $bvMetrics['book_value_per_share'];
                    }
                    if ($hasPbCol) {
                        $alert->price_to_book_ratio = $bvMetrics['price_to_book_ratio'];
                    }
                } else {
                    $existingEquity = $alert->stockholders_equity !== null ? (float) $alert->stockholders_equity : null;
                    $existingBvps = $alert->book_value_per_share !== null ? (float) $alert->book_value_per_share : null;
                    $existingPb = $alert->price_to_book_ratio !== null ? (float) $alert->price_to_book_ratio : null;

                    if ($existingEquity !== null && (! is_finite($existingEquity) || $existingEquity <= 0.0)) {
                        if ($hasBookValueCol) {
                            $alert->book_value_per_share = null;
                        }
                        if ($hasPbCol) {
                            $alert->price_to_book_ratio = null;
                        }
                    } elseif ($existingBvps !== null && (! is_finite($existingBvps) || $existingBvps < 0.0001)) {
                        if ($hasBookValueCol) {
                            $alert->book_value_per_share = null;
                        }
                        if ($hasPbCol) {
                            $alert->price_to_book_ratio = null;
                        }
                    } elseif ($existingPb !== null) {
                        if (! is_finite($existingPb) || $existingPb < StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO || round($existingPb, 2) <= 0.0) {
                            if ($hasPbCol) {
                                $alert->price_to_book_ratio = null;
                            }
                            if ($hasBookValueCol && $price !== null && $price > 0.0) {
                                $alert->book_value_per_share = null;
                            }
                        }
                    } elseif ($existingBvps !== null && $existingBvps > 0.0 && $price !== null && $price > 0.0) {
                        $computedPb = $price / $existingBvps;
                        if (is_finite($computedPb) && $computedPb >= StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO && round($computedPb, 2) > 0.0) {
                            if ($hasPbCol) {
                                $alert->price_to_book_ratio = round($computedPb, 4);
                            }
                        } else {
                            if ($hasBookValueCol) {
                                $alert->book_value_per_share = null;
                            }
                            if ($hasPbCol) {
                                $alert->price_to_book_ratio = null;
                            }
                        }
                    } elseif ($existingEquity !== null && $existingEquity > 0.0 && $shares !== null && $shares > 0 && $price !== null && $price > 0.0) {
                        $computedBvps = $existingEquity / $shares;
                        $computedPb = $computedBvps > 0.0 ? ($price / $computedBvps) : 0.0;
                        if (
                            is_finite($computedBvps)
                            && $computedBvps >= 0.0001
                            && is_finite($computedPb)
                            && $computedPb >= StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO
                            && round($computedPb, 2) > 0.0
                        ) {
                            if ($hasBookValueCol) {
                                $alert->book_value_per_share = round($computedBvps, 4);
                            }
                            if ($hasPbCol) {
                                $alert->price_to_book_ratio = round($computedPb, 4);
                            }
                        }
                    }
                }

                $breakdown = $this->breakdown($alert, $alert->setup_type);
                $alert->reason_summary = $this->syncReasonSummaryBookValue($alert->reason_summary, $breakdown);

                $scoreMeta = $this->scoreMetaFromBreakdown($breakdown);
                $rawScore = $scoreMeta['normalized'];

                $penaltyPct = (float) ($alert->liquidity_penalty_pct ?? 0.0);
                $penaltyPoints = $penaltyPct > 0.0 ? (int) round($rawScore * ($penaltyPct / 100.0)) : 0;
                $score = max(0, $rawScore - $penaltyPoints);

                $growthBonus = $this->growthSynergyBonus($alert, $alert->setup_type);
                $score = min(100, $score + $growthBonus['points']);

                $ignitionBonus = $this->ignitionBonus($alert, $alert->setup_type);
                $score = min(100, $score + $ignitionBonus['points']);

                $alert->raw_setup_score = $rawScore;
                $alert->heartbeat_score = $rawScore;
                $alert->liquidity_penalty_points = $penaltyPoints;
                $alert->setup_score = $score;

                if ($hasPrevScoreCol) {
                    $alert->previous_setup_score = $score;
                }
                if ($hasScoreDeltaCol) {
                    $alert->score_delta = 0;
                }

                $alert->saveQuietly();
                $updatedCount++;

                if ($alert->scan_run_id !== null) {
                    $affectedRuns[(int) $alert->scan_run_id] = true;
                }
            }
        });

        foreach (array_keys($affectedRuns) as $runId) {
            \Illuminate\Support\Facades\Cache::forget(\App\Services\Notifications\SetupScoringDigestBuilder::cacheKey($runId));
            if (\Illuminate\Support\Facades\Schema::hasTable('stock_buy_setup_runs')) {
                $run = \App\Models\StockBuySetupRun::find($runId);
                if ($run) {
                    $summaryData = is_array($run->summary_data) ? $run->summary_data : [];
                    unset($summaryData['digest_snapshot']);
                    $summaryData['baseline_recalculated'] = true;
                    $run->summary_data = $summaryData;
                    $run->saveQuietly();
                }
            }
        }

        try {
            app(\App\Services\Dashboard\DashboardCacheService::class)->invalidateTopScoringSetups();
        } catch (\Throwable) {
        }

        return $updatedCount;
    }

    private function inferAlertTradingCurrency(?string $exchange, string $symbol): ?string
    {
        $ex = strtoupper(trim((string) ($exchange ?? '')));
        $sym = strtoupper(trim($symbol));

        if (
            in_array($ex, ['TSX', 'TSXV', 'TSE', 'CVE', 'NEO', 'CSE'], true)
            || str_ends_with($sym, '.TO')
            || str_ends_with($sym, '.V')
            || str_ends_with($sym, '.CN')
            || str_ends_with($sym, '.NE')
        ) {
            return 'CAD';
        }

        if (in_array($ex, ['NYSE', 'NASDAQ', 'AMEX', 'NMS', 'NYQ', 'NGM', 'NCM', 'BATS', 'ARCA'], true)) {
            return 'USD';
        }

        return null;
    }

    /**
     * Synchronize the "Book value P/B ..." segment in a persisted alert's
     * reason_summary with the recalculated Book Value breakdown entry so
     * stale or invalid "Book value P/B 0.00 (10/10)" strings are cleaned up.
     *
     * @param  array<string, array<string, mixed>>  $breakdown
     */
    public function syncReasonSummaryBookValue(?string $reasonSummary, array $breakdown): ?string
    {
        if ($reasonSummary === null || trim($reasonSummary) === '') {
            return $reasonSummary;
        }

        $bvComponent = $breakdown['book_value'] ?? null;
        $newBvBit = null;
        if (
            is_array($bvComponent)
            && ($bvComponent['available'] ?? false) === true
            && (int) ($bvComponent['max'] ?? 0) > 0
            && isset($bvComponent['pb_ratio'])
            && is_numeric($bvComponent['pb_ratio'])
            && (float) $bvComponent['pb_ratio'] >= StockFundamentalsAnalyzer::MIN_VALID_PB_RATIO
            && round((float) $bvComponent['pb_ratio'], 2) > 0.0
        ) {
            $pbFormatted = number_format((float) $bvComponent['pb_ratio'], 2);
            $pts = $bvComponent['points'] ?? 0;
            $ptsFormatted = is_float($pts) && abs($pts - round($pts)) >= 0.005
                ? number_format($pts, 2)
                : (string) round((float) $pts);
            $maxFormatted = (int) $bvComponent['max'];
            $newBvBit = "Book value P/B {$pbFormatted} ({$ptsFormatted}/{$maxFormatted})";
        }

        $bvPattern = '/Book value P\/B\s+[^\;\(\n]+\(\d+(?:\.\d+)?\/\d+\)/i';
        $hasExistingBvBit = (bool) preg_match($bvPattern, $reasonSummary);

        if ($hasExistingBvBit) {
            // Collapse any duplicate Book value P/B segments first
            $deduped = (string) preg_replace(
                '/(Book value P\/B\s+[^\;\(\n]+\(\d+(?:\.\d+)?\/\d+\))(?:\s*;\s*Book value P\/B\s+[^\;\(\n]+\(\d+(?:\.\d+)?\/\d+\))+/i',
                '$1',
                $reasonSummary,
            );

            if ($newBvBit !== null) {
                return (string) preg_replace(
                    $bvPattern,
                    $newBvBit,
                    $deduped,
                );
            }

            // Remove the stale/invalid Book Value bit
            $cleaned = (string) preg_replace(
                '/\s*;\s*Book value P\/B\s+[^\;\(\n]+\(\d+(?:\.\d+)?\/\d+\)/i',
                '',
                $deduped,
            );
            $cleaned = (string) preg_replace(
                '/Book value P\/B\s+[^\;\(\n]+\(\d+(?:\.\d+)?\/\d+\)\s*;\s*/i',
                '',
                $cleaned,
            );
            $cleaned = (string) preg_replace(
                $bvPattern,
                '',
                $cleaned,
            );
            // If Fundamentals section is now empty ("Fundamentals:\n."), remove it
            $cleaned = (string) preg_replace('/\n\nFundamentals:\s*\.\s*$/i', '', $cleaned);

            return trim($cleaned);
        }

        if ($newBvBit !== null) {
            if (preg_match('/Fundamentals:\n([^\n]+)\.\s*$/', $reasonSummary)) {
                return (string) preg_replace(
                    '/(Fundamentals:\n[^\n]+)\.\s*$/',
                    '$1; '.$newBvBit.'.',
                    $reasonSummary,
                );
            }

            if (str_starts_with(trim($reasonSummary), 'Technical:')) {
                return rtrim($reasonSummary)."\n\nFundamentals:\n{$newBvBit}.";
            }
        }

        return $reasonSummary;
    }
}
