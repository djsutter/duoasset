<?php

namespace App\Services\Stocks\Algorithms;

use App\Services\Stocks\Algorithms\Concerns\SharedDetectionHelpers;
use App\Services\Stocks\Indicators;
use App\Services\Stocks\StockBuySetupResult;
use Carbon\CarbonImmutable;

/**
 * O'Neil-style follow-through-day detector — catches a move in its first
 * 1-3 days, rather than after a mature multi-week base like Heartbeat.
 *
 * Unlike Heartbeat (rare-volume spike day) or Range Compression (percentile
 * squeeze), this algorithm looks for a short undercut+recovery sequence:
 *
 * 1. An "undercut day" U — a fresh short-term low (a shakeout below the
 *    preceding `undercut_lookback_days` bars).
 * 2. A "follow-through day" F within the next `followthrough_max_days`
 *    bars after U, closing up >= `followthrough_min_gain_pct`% versus its
 *    own previous close, on volume >= `followthrough_volume_multiplier`x
 *    its own trailing 50-bar average volume.
 *
 * The base used for quality metrics (range, ATR, volume dry-up, slope) is
 * the `min_base_days`..`max_base_days` window immediately *preceding* U —
 * never including U or F — so the setup's own undercut/follow-through
 * bars can never contaminate their own base reading.
 *
 * Candidates are scanned "candidate-day-first, window-strictly-before":
 * U is scanned from most recent backward within the configured recent
 * window, and for each U the base window strictly precedes it. This
 * mirrors the search pattern used by RangeCompressionBreakoutAlgorithm.
 */
class EarlyBreakoutFollowThroughAlgorithm implements BuySetupAlgorithm
{
    use SharedDetectionHelpers;

    public function key(): string
    {
        return 'early_breakout_followthrough';
    }

    public function label(): string
    {
        return 'Early breakout follow-through';
    }

    public function detect(array $bars, array $benchmarkBars, array $context, array $typeConfig, string $setupType): ?StockBuySetupResult
    {
        $this->lastRejectionReason = null;

        $minBase = (int) ($typeConfig['min_base_days'] ?? 45);
        $maxBase = (int) ($typeConfig['max_base_days'] ?? 120);
        $recentWindow = (int) ($typeConfig['recent_spike_window_days'] ?? 60);
        $undercutLookback = (int) ($typeConfig['undercut_lookback_days'] ?? 10);
        $followthroughMaxDays = (int) ($typeConfig['followthrough_max_days'] ?? 4);
        $followthroughMinGainPct = (float) ($typeConfig['followthrough_min_gain_pct'] ?? 1.5);
        $followthroughVolumeMultiplier = (float) ($typeConfig['followthrough_volume_multiplier'] ?? 1.25);

        $bars = array_values($bars);
        $n = count($bars);
        if ($n < 252) {
            return $this->reject("insufficient history ({$n} < 252 bars)");
        }

        $symbol = strtoupper((string) ($context['symbol'] ?? ''));
        $marketCap = $context['market_cap'] ?? null;
        if (($reason = $this->marketCapRejectionReason($marketCap, $typeConfig)) !== null) {
            return $this->reject($reason);
        }

        // ---------------- Undercut-day-first search ----------------
        // Scan candidate undercut days U from most recent backward, within
        // the last $recentWindow bars. For each candidate, the base is the
        // window strictly *before* U (never including U), and the
        // follow-through day F is searched strictly *after* U. The first
        // (U, F) pair found — starting from the most recent U, and the
        // nearest qualifying F after it — is accepted and the search stops.
        $recentThreshold = $n - $recentWindow;
        $undercutIdx = null;
        $followThroughIdx = null;
        $baseStartIdx = null;
        $baseEndIdx = null;
        $gainPctFound = null;
        $volumeMultipleFound = null;

        for ($u = $n - 1; $u >= max(0, $recentThreshold); $u--) {
            $lookbackStart = max(0, $u - $undercutLookback);
            $lookbackEnd = $u - 1;
            if ($lookbackEnd < $lookbackStart) {
                continue;
            }

            $lookbackBars = array_slice($bars, $lookbackStart, $lookbackEnd - $lookbackStart + 1);
            $lookbackLows = array_filter(array_map(fn ($b) => (float) ($b['low'] ?? 0), $lookbackBars), fn ($v) => $v > 0);
            if (empty($lookbackLows)) {
                continue;
            }

            $undercutLow = (float) ($bars[$u]['low'] ?? 0);
            if ($undercutLow <= 0 || $undercutLow > min($lookbackLows)) {
                continue;
            }

            $candidateBaseEndIdx = $u - 1;
            $candidateBaseStartIdx = max(0, $candidateBaseEndIdx - $maxBase + 1);
            $candidateBaseLen = $candidateBaseEndIdx - $candidateBaseStartIdx + 1;
            if ($candidateBaseLen < $minBase) {
                continue;
            }

            $fEnd = min($n - 1, $u + $followthroughMaxDays);
            for ($f = $u + 1; $f <= $fEnd; $f++) {
                $prevClose = (float) ($bars[$f - 1]['close'] ?? 0);
                $close = (float) ($bars[$f]['close'] ?? 0);
                if ($prevClose <= 0) {
                    continue;
                }

                $gainPct = (($close - $prevClose) / $prevClose) * 100;
                if ($gainPct < $followthroughMinGainPct) {
                    continue;
                }

                $avgStart = max(0, $f - 50);
                $avgEnd = $f - 1;
                $avgLen = $avgEnd - $avgStart + 1;
                if ($avgLen < $minBase) {
                    // Not enough trailing history for a meaningful average.
                    continue;
                }

                $avgVolBars = array_slice($bars, $avgStart, $avgLen);
                $avgVolSum = 0;
                foreach ($avgVolBars as $b) {
                    $avgVolSum += (int) ($b['volume'] ?? 0);
                }
                $avgVol = $avgVolSum / count($avgVolBars);
                $volume = (int) ($bars[$f]['volume'] ?? 0);
                if ($avgVol <= 0) {
                    continue;
                }

                $volumeMultiple = $volume / $avgVol;
                if ($volumeMultiple < $followthroughVolumeMultiplier) {
                    continue;
                }

                $undercutIdx = $u;
                $followThroughIdx = $f;
                $baseStartIdx = $candidateBaseStartIdx;
                $baseEndIdx = $candidateBaseEndIdx;
                $gainPctFound = $gainPct;
                $volumeMultipleFound = $volumeMultiple;
                break 2;
            }
        }

        if ($followThroughIdx === null) {
            // No qualifying undercut/follow-through pair found in the
            // recent window. Fall back to the single most recent base
            // window (ending at the very last bar) so a still-forming
            // setup remains visible, scored with 0 rarity points, rather
            // than disappearing.
            $baseEndIdx = $n - 1;
            $baseStartIdx = max(0, $baseEndIdx - $maxBase + 1);
            $baseLen = $baseEndIdx - $baseStartIdx + 1;
            if ($baseLen < $minBase) {
                return $this->reject("base too short ({$baseLen} < {$minBase} bars)");
            }

            $spikeIdx = $baseEndIdx;
            $spikeRarityPoints = 0;
            $spikeRarityDescription = 'No follow-through confirmed yet';
        } else {
            $baseLen = $baseEndIdx - $baseStartIdx + 1;
            $spikeIdx = $followThroughIdx;
            $barsAfterUndercut = $followThroughIdx - $undercutIdx;

            // Score the follow-through's strength on a 0-7 scale by
            // combining how far the gain% and volume multiple sit above
            // their configured minimum thresholds:
            //   Gain component (0-4), based on gain% above the minimum:
            //     +5.0pp or more above minimum -> 4
            //     +3.0pp or more above minimum -> 3
            //     +1.0pp or more above minimum -> 2
            //     otherwise (>= minimum)       -> 1
            //   Volume component (0-3), based on volume multiple above
            //   the minimum multiplier:
            //     +2.0x or more above minimum -> 3
            //     +1.0x or more above minimum -> 2
            //     otherwise (>= minimum)      -> 1
            // Total = gain component + volume component, capped at 7.
            $gainExcess = $gainPctFound - $followthroughMinGainPct;
            $volExcess = $volumeMultipleFound - $followthroughVolumeMultiplier;

            $gainPoints = match (true) {
                $gainExcess >= 5.0 => 4,
                $gainExcess >= 3.0 => 3,
                $gainExcess >= 1.0 => 2,
                default => 1,
            };
            $volPoints = match (true) {
                $volExcess >= 2.0 => 3,
                $volExcess >= 1.0 => 2,
                default => 1,
            };
            $spikeRarityPoints = min(7, $gainPoints + $volPoints);
            $spikeRarityDescription = 'Follow-through day: +'.number_format($gainPctFound, 1).'% on '
                .number_format($volumeMultipleFound, 1)."x avg volume, {$barsAfterUndercut} bars after undercut low";
        }

        $baseBars = array_slice($bars, $baseStartIdx, $baseLen);
        $highs = array_map(fn ($b) => (float) ($b['high'] ?? 0), $baseBars);
        $lows = array_filter(array_map(fn ($b) => (float) ($b['low'] ?? 0), $baseBars), fn ($v) => $v > 0);
        $baseHigh = empty($highs) ? 0.0 : max($highs);
        $baseLow = empty($lows) ? 0.0 : min($lows);

        $spikeVol = (int) ($bars[$spikeIdx]['volume'] ?? 0);
        $spikeAgeBars = $n - 1 - $spikeIdx;

        $prior52Start = max(0, $spikeIdx - 252);
        $prior52wMax = 0;
        for ($i = $prior52Start; $i < $spikeIdx; $i++) {
            $prior52wMax = max($prior52wMax, (int) ($bars[$i]['volume'] ?? 0));
        }
        $max104w = 0;
        if ($spikeIdx >= 504) {
            for ($i = $spikeIdx - 504; $i < $spikeIdx; $i++) {
                $max104w = max($max104w, (int) ($bars[$i]['volume'] ?? 0));
            }
        }
        $is52w = $prior52wMax > 0 && $spikeVol > $prior52wMax;
        $is104w = $max104w > 0 && $spikeVol > $max104w;

        // Days since a comparable prior volume day (>= 80% of anchor volume).
        $threshold = (int) floor($spikeVol * 0.8);
        $prevSpike = null;
        $comparisonStart = max(0, $spikeIdx - 504);
        for ($i = $spikeIdx - 1; $i >= $comparisonStart; $i--) {
            if ((int) ($bars[$i]['volume'] ?? 0) >= $threshold) {
                $prevSpike = $spikeIdx - $i;
                break;
            }
        }

        // ---------------- Quality metrics (shared across all algorithms) ----------------
        $closes = array_map(fn ($b) => (float) ($b['close'] ?? 0), $baseBars);
        $atrEarly = Indicators::atr(array_slice($baseBars, 0, min(30, $baseLen)), 14);
        $atrLate = Indicators::atr(array_slice($baseBars, -min(30, $baseLen)), 14);
        $atrRatio = ($atrEarly && $atrEarly > 0 && $atrLate !== null) ? $atrLate / $atrEarly : 1.0;
        $slope = Indicators::slope($closes);

        $baseVols = array_map(fn ($b) => (int) ($b['volume'] ?? 0), $baseBars);
        $baseAvgVol = array_sum($baseVols) / max(1, count($baseVols));

        $compStart = max(0, $baseStartIdx - 60);
        $compBars = array_slice($bars, $compStart, $baseStartIdx - $compStart);
        $compAvgVol = 0.0;
        if (! empty($compBars)) {
            $sum = 0;
            foreach ($compBars as $b) {
                $sum += (int) ($b['volume'] ?? 0);
            }
            $compAvgVol = $sum / count($compBars);
        }
        $volumeDryUp = $compAvgVol > 0 ? 1.0 - ($baseAvgVol / $compAvgVol) : 0.0;

        $spikeClose = (float) ($bars[$spikeIdx]['close'] ?? 0);
        $distToBreakout = $baseHigh > 0 ? (($baseHigh - $spikeClose) / $baseHigh) * 100 : 0.0;

        $closesUpToSpike = [];
        for ($i = 0; $i <= $spikeIdx; $i++) {
            $closesUpToSpike[] = (float) ($bars[$i]['close'] ?? 0);
        }
        $sma50 = Indicators::sma($closesUpToSpike, 50);
        $sma150 = Indicators::sma($closesUpToSpike, 150);
        $sma200 = Indicators::sma($closesUpToSpike, 200);
        $maAlignment = $this->maAlignmentString($spikeClose, $sma50, $sma150, $sma200);

        $rs = $this->relativeStrength($bars, $benchmarkBars);

        $rangePct = $baseLow > 0 ? (($baseHigh - $baseLow) / $baseLow) * 100 : 0.0;

        $result = new StockBuySetupResult(
            symbol: $symbol,
            setupType: $setupType,
            companyName: $context['company_name'] ?? null,
            exchange: $context['exchange'] ?? null,
            marketCap: is_numeric($marketCap) ? (int) $marketCap : null,
            marketCapCategory: $this->marketCapCategory(is_numeric($marketCap) ? (int) $marketCap : null),
            spikeDate: CarbonImmutable::parse($bars[$spikeIdx]['date']),
            spikeVolume: $spikeVol,
            prior52wMaxVolume: $prior52wMax,
            max104wVolume: $max104w,
            is52wHighVolume: $is52w,
            is104wHighVolume: $is104w,
            daysSincePreviousComparableSpike: $prevSpike,
            spikeAgeBars: $spikeAgeBars,
            spikeRarityPoints: $spikeRarityPoints,
            spikeRarityDescription: $spikeRarityDescription,
            baseStart: CarbonImmutable::parse($bars[$baseStartIdx]['date']),
            baseEnd: CarbonImmutable::parse($bars[$baseEndIdx]['date']),
            baseDurationDays: $baseLen,
            baseHigh: $baseHigh,
            baseLow: $baseLow,
            rangeCompressionPct: round($rangePct, 4),
            atrContractionRatio: round($atrRatio, 4),
            volumeDryUpScore: round($volumeDryUp, 4),
            slope: $slope,
            distanceToBreakoutPct: round($distToBreakout, 4),
            maAlignment: $maAlignment,
            relativeStrengthScore: $rs,
            earningsAcceleration: $this->nullableFloat($context['earnings_acceleration'] ?? null),
            salesAcceleration: $this->nullableFloat($context['sales_acceleration'] ?? null),
            quarterlyEpsGrowthPct: $this->nullableFloat($context['quarterly_eps_growth_pct'] ?? null),
            quarterlyRevenueGrowthPct: $this->nullableFloat($context['quarterly_revenue_growth_pct'] ?? null),
            annualEpsGrowthPct: $this->nullableFloat($context['annual_eps_growth_pct'] ?? null),
            roePct: $this->nullableFloat($context['roe_pct'] ?? null),
            profitMarginPct: $this->nullableFloat($context['profit_margin_pct'] ?? null),
            spikeRelativeVolume: $baseAvgVol > 0 ? round($spikeVol / $baseAvgVol, 4) : null,
            epsGrowthSequence: $context['eps_growth_sequence'] ?? null,
            revenueGrowthSequence: $context['revenue_growth_sequence'] ?? null,
            operatingMarginExpansionBps: $this->nullableFloat($context['operating_margin_expansion_bps'] ?? null),
            currentTtmOperatingMargin: $this->nullableFloat($context['current_ttm_operating_margin'] ?? null),
            priorTtmOperatingMargin: $this->nullableFloat($context['prior_ttm_operating_margin'] ?? null),
            fcfMarginExpansionBps: $this->nullableFloat($context['fcf_margin_expansion_bps'] ?? null),
            currentTtmFcfMargin: $this->nullableFloat($context['current_ttm_fcf_margin'] ?? null),
            priorTtmFcfMargin: $this->nullableFloat($context['prior_ttm_fcf_margin'] ?? null),
            price: $this->nullableFloat($context['price'] ?? null),
            sharesOutstanding: $this->nullableInt($context['shares_outstanding'] ?? null),
            floatShares: $this->nullableInt($context['float_shares'] ?? null),
            freeFloat: $this->nullableFloat($context['free_float'] ?? null),
        );

        $priorYearRevenue = $this->nullableFloat($context['prior_year_revenue'] ?? null);
        $technical = "Technical:\n{$spikeRarityDescription}; base {$baseLen}d (range ".number_format($rangePct, 1).'%); ATR ratio '.number_format($atrRatio, 2)
            .($rs !== null ? '; RS '.($rs >= 0 ? '+' : '').number_format($rs, 1) : '')
            .'; dist to bo '.number_format($distToBreakout, 1).'%.';
        $fundamentals = $this->fundamentalsReasonParagraph($result, $priorYearRevenue);
        $result->reasonSummary = $fundamentals === '' ? $technical : "{$technical}\n\n{$fundamentals}";

        return $result;
    }
}
