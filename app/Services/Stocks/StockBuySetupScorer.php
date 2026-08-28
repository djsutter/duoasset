<?php

namespace App\Services\Stocks;

use App\Models\StockBuySetupAlert;

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
                'points' => $this->maAlignmentPoints((string) ($r->maAlignment ?? $r->ma_alignment ?? ''), $weights['ma_alignment']),
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
                'label' => 'Operating margin expansion',
                'points' => $this->operatingMarginExpansionPoints(
                    $this->nullableFloat($r->operatingMarginExpansionBps ?? $r->operating_margin_expansion_bps ?? null),
                    $weights['operating_margin_expansion'] ?? 0,
                    $type,
                ),
                'max' => $weights['operating_margin_expansion'] ?? 0,
                'value' => $this->operatingMarginExpansionValue($r),
            ],
            'fcf_margin_expansion' => [
                'label' => 'FCF margin expansion',
                'points' => $this->fcfMarginExpansionPoints(
                    $this->nullableFloat($r->fcfMarginExpansionBps ?? $r->fcf_margin_expansion_bps ?? null),
                    $weights['fcf_margin_expansion'] ?? 0,
                    $type,
                ),
                'max' => $weights['fcf_margin_expansion'] ?? 0,
                'value' => $this->fcfMarginExpansionValue($r),
            ],
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
     * @param  array<string, array{points: int, max: int}>  $breakdown
     */
    public function scoreFromBreakdown(array $breakdown): int
    {
        $max = (int) array_sum(array_column($breakdown, 'max'));
        if ($max <= 0) {
            return 0;
        }

        $raw = (int) array_sum(array_column($breakdown, 'points'));

        return max(0, min(100, (int) round(($raw / $max) * 100)));
    }

    /**
     * @param  array<string, array{points: int, max: int}>  $breakdown
     * @return array{raw: int, max: int, normalized: int}
     */
    public function scoreMetaFromBreakdown(array $breakdown): array
    {
        $raw = (int) array_sum(array_column($breakdown, 'points'));
        $max = (int) array_sum(array_column($breakdown, 'max'));

        return [
            'raw' => $raw,
            'max' => $max,
            'normalized' => $max > 0 ? max(0, min(100, (int) round(($raw / $max) * 100))) : 0,
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

    private function maAlignmentPoints(string $alignment, int $max): int
    {
        if ($max <= 0) {
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

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
