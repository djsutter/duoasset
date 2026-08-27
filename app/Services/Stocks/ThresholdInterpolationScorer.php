<?php

namespace App\Services\Stocks;

/**
 * Reusable "higher is better" threshold interpolation scorer.
 *
 * Maps a raw metric value onto a 0-100 scale using four configurable
 * threshold checkpoints: threshold_25, threshold_50, threshold_75 and
 * threshold_100. Scores are linearly interpolated between checkpoints
 * (and between zero and threshold_25) instead of jumping discretely.
 *
 * Values at or below zero always score 0 — this scorer intentionally does
 * not penalize/extrapolate negative values below zero. Values at or above
 * threshold_100 are capped at 100.
 *
 * First consumer: Operating Margin Expansion. Designed to be reused by
 * future "expansion" style metrics (e.g. Free Cash Flow Margin Expansion).
 */
class ThresholdInterpolationScorer
{
    public function score(
        float $value,
        float $threshold25,
        float $threshold50,
        float $threshold75,
        float $threshold100,
    ): float {
        if ($value <= 0.0) {
            return 0.0;
        }

        // Defensive guard: thresholds must be positive and strictly
        // increasing for interpolation to be meaningful. Configuration
        // validation should prevent this, but never divide by zero here.
        if (! ($threshold25 > 0 && $threshold25 < $threshold50 && $threshold50 < $threshold75 && $threshold75 < $threshold100)) {
            return 0.0;
        }

        $score = match (true) {
            $value <= $threshold25 => $this->interpolate($value, 0.0, 0.0, $threshold25, 25.0),
            $value <= $threshold50 => $this->interpolate($value, $threshold25, 25.0, $threshold50, 50.0),
            $value <= $threshold75 => $this->interpolate($value, $threshold50, 50.0, $threshold75, 75.0),
            $value <= $threshold100 => $this->interpolate($value, $threshold75, 75.0, $threshold100, 100.0),
            default => 100.0,
        };

        return max(0.0, min(100.0, $score));
    }

    private function interpolate(float $value, float $x1, float $y1, float $x2, float $y2): float
    {
        if ($x2 <= $x1) {
            return $y2;
        }

        return $y1 + (($value - $x1) / ($x2 - $x1)) * ($y2 - $y1);
    }
}
