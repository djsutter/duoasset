<?php

namespace App\Services\Stocks;

/**
 * Produces a 0–100 composite heartbeat score from a StockBuySetupResult.
 *
 * Each subscore is documented inline so thresholds can be tuned without
 * reading the scanner. Subscores are summed then clamped to [0, 100].
 */
class StockBuySetupScorer
{
    public function score(StockBuySetupResult $r): int
    {
        $s = 0;

        // Spike rarity (max 25 pts)
        if ($r->is104wHighVolume) {
            $s += 25;
        } elseif ($r->is52wHighVolume) {
            $s += 15;
        } else {
            $s += 5;
        }

        // Base duration (max 10 pts)
        if ($r->baseDurationDays >= 90) {
            $s += 10;
        } elseif ($r->baseDurationDays >= 60) {
            $s += 7;
        } else {
            $s += 3;
        }

        // Range compression (max 15 pts)
        if ($r->rangeCompressionPct <= 10) {
            $s += 15;
        } elseif ($r->rangeCompressionPct <= 18) {
            $s += 10;
        } elseif ($r->rangeCompressionPct <= 25) {
            $s += 5;
        }

        // ATR contraction (max 10 pts)
        if ($r->atrContractionRatio <= 0.6) {
            $s += 10;
        } elseif ($r->atrContractionRatio <= 0.75) {
            $s += 7;
        } elseif ($r->atrContractionRatio <= 0.85) {
            $s += 4;
        }

        // Volume dry-up (max 10 pts)
        if ($r->volumeDryUpScore >= 0.3) {
            $s += 10;
        } elseif ($r->volumeDryUpScore >= 0.15) {
            $s += 6;
        } elseif ($r->volumeDryUpScore > 0) {
            $s += 3;
        }

        // Distance to breakout (max 10 pts) — closer is better.
        if ($r->distanceToBreakoutPct <= 2) {
            $s += 10;
        } elseif ($r->distanceToBreakoutPct <= 5) {
            $s += 7;
        } elseif ($r->distanceToBreakoutPct <= 10) {
            $s += 4;
        }

        // MA alignment (max 10 pts)
        if (str_contains($r->maAlignment, '50>150>200') && str_contains($r->maAlignment, 'price>50')) {
            $s += 10;
        } elseif (str_contains($r->maAlignment, '50>200')) {
            $s += 5;
        }

        // Relative strength (max 10 pts) — omitted when null.
        if ($r->relativeStrengthScore !== null) {
            if ($r->relativeStrengthScore >= 10) {
                $s += 10;
            } elseif ($r->relativeStrengthScore >= 0) {
                $s += 5;
            }
        }

        // Earnings / sales acceleration bonuses (max 5 each).
        if ($r->earningsAcceleration !== null && $r->earningsAcceleration > 0) {
            $s += 5;
        }
        if ($r->salesAcceleration !== null && $r->salesAcceleration > 0) {
            $s += 5;
        }

        return max(0, min(100, $s));
    }
}
