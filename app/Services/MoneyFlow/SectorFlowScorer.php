<?php

namespace App\Services\MoneyFlow;

/**
 * Pure scoring math for the Sector Money Flows engine.
 *
 * Every raw metric is mapped to an ABSOLUTE 0-100 component score anchored to
 * the ETF's own history (return vs own volatility; relative volume vs its own
 * average) via a tanh squash — never against the other sectors. Cross-sectional
 * ranking is computed elsewhere and deliberately kept out of these scores.
 *
 * No I/O, no DB — safe to unit test directly. All knobs come from
 * config('market_data.moneyflow.*').
 */
class SectorFlowScorer
{
    /** @var array<string, float> */
    private array $scoreWeights;

    /** @var array<string, float> */
    private array $timeframeWeights;

    private float $changeZScale;

    private float $relativeVolumeScale;

    /** @var array<string, float> */
    private array $relativeStrengthScale;

    public function __construct()
    {
        $cfg = config('market_data.moneyflow');

        $this->scoreWeights = array_map('floatval', (array) ($cfg['score_weights'] ?? []));
        $this->timeframeWeights = array_map('floatval', (array) ($cfg['timeframe_weights'] ?? []));
        $this->changeZScale = (float) ($cfg['normalization']['change_z_scale'] ?? 2.0);
        $this->relativeVolumeScale = (float) ($cfg['normalization']['relative_volume_scale'] ?? 1.0);
        $this->relativeStrengthScale = array_map('floatval', (array) ($cfg['normalization']['relative_strength_scale'] ?? []));
    }

    /**
     * Absolute change score: the ETF's period return expressed as a z-score
     * against its own historical distribution of same-length returns, squashed
     * to 0-100. A move of ~change_z_scale sigmas lands near ~88/12.
     */
    public function scoreChange(?float $return, ?float $sigma): ?float
    {
        if ($return === null) {
            return null;
        }
        if ($sigma === null || $sigma <= 0.0) {
            // No usable volatility baseline — treat as neutral rather than
            // manufacturing an extreme score from a bare return.
            return 50.0;
        }

        return $this->squash(($return / $sigma) / $this->changeZScale);
    }

    /**
     * Absolute relative-strength score: ETF return minus benchmark return
     * (percentage points), squashed against a per-timeframe scale.
     */
    public function scoreRelativeStrength(?float $relativeStrength, string $timeframe): ?float
    {
        if ($relativeStrength === null) {
            return null;
        }
        $scale = $this->relativeStrengthScale[$timeframe] ?? 2.0;
        if ($scale <= 0.0) {
            $scale = 2.0;
        }

        return $this->squash($relativeStrength / $scale);
    }

    /**
     * Absolute relative-volume score: participation vs the ETF's own average
     * (1.0 = average -> 50). Higher participation lifts the score.
     */
    public function scoreRelativeVolume(?float $relativeVolume): ?float
    {
        if ($relativeVolume === null) {
            return null;
        }
        $scale = $this->relativeVolumeScale > 0.0 ? $this->relativeVolumeScale : 1.0;

        return $this->squash(($relativeVolume - 1.0) / $scale);
    }

    /**
     * Blend the three component scores for one timeframe using score_weights,
     * renormalized over whichever components are present. Null if none.
     */
    public function blendComponents(?float $changeScore, ?float $relativeStrengthScore, ?float $relativeVolumeScore): ?float
    {
        $pairs = [
            'change' => $changeScore,
            'relative_strength' => $relativeStrengthScore,
            'relative_volume' => $relativeVolumeScore,
        ];

        $sum = 0.0;
        $weightSum = 0.0;
        foreach ($pairs as $key => $value) {
            if ($value === null) {
                continue;
            }
            $w = $this->scoreWeights[$key] ?? 0.0;
            if ($w <= 0.0) {
                continue;
            }
            $sum += $value * $w;
            $weightSum += $w;
        }

        return $weightSum > 0.0 ? $this->clamp($sum / $weightSum) : null;
    }

    /**
     * Composite strength: weighted average of the per-timeframe scores using
     * timeframe_weights, renormalized over available timeframes. Null if none.
     *
     * @param  array<string, float|null>  $timeframeScores  keyed by timeframe
     */
    public function compositeStrength(array $timeframeScores): ?float
    {
        return $this->weightedAverage($timeframeScores, $this->timeframeWeights, clamp: true);
    }

    /**
     * Weighted average of per-timeframe values (velocity/acceleration
     * composites), renormalized over available timeframes. Not clamped.
     *
     * @param  array<string, float|null>  $timeframeValues  keyed by timeframe
     */
    public function compositeByTimeframe(array $timeframeValues): ?float
    {
        return $this->weightedAverage($timeframeValues, $this->timeframeWeights, clamp: false);
    }

    /**
     * @param  array<string, float|null>  $values
     * @param  array<string, float>  $weights
     */
    private function weightedAverage(array $values, array $weights, bool $clamp): ?float
    {
        $sum = 0.0;
        $weightSum = 0.0;
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $w = $weights[$key] ?? 0.0;
            if ($w <= 0.0) {
                continue;
            }
            $sum += $value * $w;
            $weightSum += $w;
        }

        if ($weightSum <= 0.0) {
            return null;
        }

        $avg = $sum / $weightSum;

        return $clamp ? $this->clamp($avg) : $avg;
    }

    /**
     * Map a signed z-like value to 0-100 via a tanh squash centered at 50.
     */
    private function squash(float $z): float
    {
        return $this->clamp(50.0 + 50.0 * tanh($z));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
