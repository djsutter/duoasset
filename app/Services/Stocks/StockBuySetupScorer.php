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
    public function breakdown(StockBuySetupResult|StockBuySetupAlert $r): array
    {
        $weights = $this->weights();

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
                'points' => $this->positiveBonusPoints($this->nullableFloat($r->earningsAcceleration ?? $r->earnings_acceleration ?? null), $weights['earnings_acceleration']),
                'max' => $weights['earnings_acceleration'],
                'value' => ($this->nullableFloat($r->earningsAcceleration ?? $r->earnings_acceleration ?? null) !== null) ? number_format((float) ($r->earningsAcceleration ?? $r->earnings_acceleration), 1).' pts' : 'n/a',
            ],
            'sales_acceleration' => [
                'label' => 'Sales accel.',
                'points' => $this->positiveBonusPoints($this->nullableFloat($r->salesAcceleration ?? $r->sales_acceleration ?? null), $weights['sales_acceleration']),
                'max' => $weights['sales_acceleration'],
                'value' => ($this->nullableFloat($r->salesAcceleration ?? $r->sales_acceleration ?? null) !== null) ? number_format((float) ($r->salesAcceleration ?? $r->sales_acceleration), 1).' pts' : 'n/a',
            ],
        ];
    }

    public function score(StockBuySetupResult $r): int
    {
        return $this->scoreFromBreakdown($this->breakdown($r));
    }

    public function scoreFromAlert(StockBuySetupAlert $alert): int
    {
        return $this->scoreFromBreakdown($this->breakdown($alert));
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
    public function weights(): array
    {
        $defaults = [
            'spike_rarity' => 7,
            'base_duration' => 10,
            'range_compression' => 15,
            'atr_contraction' => 10,
            'volume_dry_up' => 10,
            'breakout_distance' => 10,
            'ma_alignment' => 10,
            'relative_strength' => 10,
            'earnings_acceleration' => 5,
            'sales_acceleration' => 5,
        ];

        $configured = (array) config('market_data.buy_setup_scanner.score_weights', []);

        return collect($defaults)
            ->mapWithKeys(function (int $default, string $key) use ($configured) {
                $weight = max(0, (int) ($configured[$key] ?? $default));

                // Spike rarity is intentionally capped at seven points. It is
                // a probability bonus, not a qualification gate.
                if ($key === 'spike_rarity') {
                    $weight = min(7, $weight);
                }

                return [$key => $weight];
            })
            ->all();
    }

    private function spikeRarityPoints(StockBuySetupResult|StockBuySetupAlert $r, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        $points = (int) ($r->spikeRarityPoints ?? $r->spike_rarity_points ?? 0);

        return min($max, max(0, $points));
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
        return match (true) {
            $max <= 0 => 0,
            $pct <= 2 => $max,
            $pct <= 5 => (int) round($max * 0.70),
            $pct <= 10 => (int) round($max * 0.40),
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

    private function positiveBonusPoints(?float $value, int $max): int
    {
        return $max > 0 && $value !== null && $value > 0 ? $max : 0;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
