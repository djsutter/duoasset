<?php

namespace App\Services\Earnings;

use App\Models\EarningsEvent;

class EarningsSurpriseScorer
{
    /**
     * Compute a prioritization score for an earnings event.
     * Higher is "more interesting". Alert eligibility is decided separately.
     */
    public function score(EarningsEvent $event): int
    {
        $score = 0;

        $epsPct = (float) ($event->eps_surprise_percent ?? 0);
        if ($epsPct >= 88) {
            $score += 40;
        }
        if ($epsPct >= 150) {
            $score += 15;
        }
        if ($epsPct >= 300) {
            $score += 15;
        }

        $marketCap = (int) ($event->market_cap ?? 0);
        if ($marketCap >= 100_000_000) {
            $score += 10;
        }
        if ($marketCap >= 1_000_000_000) {
            $score += 10;
        }

        $revPct = $event->revenue_surprise_percent;
        if ($revPct !== null) {
            if ((float) $revPct > 0) {
                $score += 10;
            }
            if ((float) $revPct >= 5) {
                $score += 10;
            }
        }

        $relVol = $event->relative_volume;
        if ($relVol !== null) {
            if ((float) $relVol >= 2) {
                $score += 10;
            }
            if ((float) $relVol >= 5) {
                $score += 10;
            }
        }

        return $score;
    }

    /**
     * EPS surprise percent computed from estimate/actual, or null if undefined.
     */
    public static function calculateSurprisePercent(?float $actual, ?float $estimated): ?float
    {
        if ($actual === null || $estimated === null) {
            return null;
        }

        // Percentage surprise becomes economically meaningless when the EPS
        // estimate is effectively zero. Avoid allowing a fraction-of-a-cent
        // estimate to generate a multi-thousand-percent surprise.
        if (abs($estimated) < 0.01) {
            return null;
        }

        return (($actual - $estimated) / abs($estimated)) * 100.0;
    }
}
