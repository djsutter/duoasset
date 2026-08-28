<?php

namespace App\Services\Stocks\Algorithms;

use App\Services\Stocks\Algorithms\Concerns\SharedDetectionHelpers;
use App\Services\Stocks\Indicators;
use App\Services\Stocks\StockBuySetupResult;
use Carbon\CarbonImmutable;

/**
 * Bottoming / quiet-accumulation detector — the structural opposite of
 * Heartbeat Consolidation + Spike (which requires a plateau near highs and
 * a historically rare high-volume day). No high-volume spike is required
 * at all here; accumulation is meant to be quiet.
 *
 * Detection combines four signals into a single 0-7 point score (reusing
 * the "spike rarity" score slot for compatibility with StockBuySetupScorer,
 * which is shared across every algorithm per setup type config):
 *
 *  1. Prior decline (SCORED, not a hard gate): the % decline from the
 *     highest high in a lookback window immediately before the base to the
 *     base's own low. >= min_decline_pct is a "qualifying" reversal.
 *  2. Floor-touch count: bars inside the base whose low sits within
 *     floor_touch_tolerance_pct% of the base's own low, counting only
 *     touches separated by >= floor_touch_min_gap_days bars so the same
 *     touch isn't double-counted. >= 2 touches is a "confirmed" floor.
 *  3. Accumulation ratio (OBV-style proxy): average volume on up-days
 *     divided by average volume on down-days across the base. > 1 means
 *     buyers were more active than sellers by volume (quiet accumulation).
 *  4. An optional bullish RSI/price-divergence bonus: price making an
 *     equal/lower low in the base's second half than its first half while
 *     Wilder's RSI (computed only from closes strictly up to each low bar)
 *     makes a *higher* low there.
 *
 * The "anchor day" (spikeDate/spikeVolume/spikeAgeBars/etc.) defaults to
 * the base window's last bar. If a later day within the recent window
 * closes back above the base's own high on above-average (vs the base)
 * volume, that confirmation/recovery day is used as the anchor instead,
 * with one extra point of credit — mirroring the "no confirming event yet
 * → anchor to the last base bar with 0 extra credit" fallback philosophy
 * used by the other algorithms rather than hard-rejecting.
 */
class FloorReversalAccumulationAlgorithm implements BuySetupAlgorithm
{
    use SharedDetectionHelpers;

    public function key(): string
    {
        return 'floor_reversal_accumulation';
    }

    public function label(): string
    {
        return 'Floor reversal / accumulation';
    }

    public function detect(array $bars, array $benchmarkBars, array $context, array $typeConfig, string $setupType): ?StockBuySetupResult
    {
        $this->lastRejectionReason = null;

        $minBase = (int) ($typeConfig['min_base_days'] ?? 45);
        $maxBase = (int) ($typeConfig['max_base_days'] ?? 120);
        $recentWindow = (int) ($typeConfig['recent_spike_window_days'] ?? 60);
        $declineLookback = (int) ($typeConfig['decline_lookback_days'] ?? 90);
        $minDeclinePct = (float) ($typeConfig['min_decline_pct'] ?? 15.0);
        $floorTouchTolerancePct = (float) ($typeConfig['floor_touch_tolerance_pct'] ?? 3.0);
        $floorTouchMinGapDays = (int) ($typeConfig['floor_touch_min_gap_days'] ?? 5);

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

        // ---------------- Confirmation-day-first search ----------------
        // Scan candidate confirmation/recovery days from most recent
        // backward, within the recent window. For each candidate day $c,
        // the base is the maxBase-day window strictly *before* it (never
        // including $c itself, so the confirmation bar can never
        // contaminate its own base's high/low/volume reading). The
        // candidate is accepted once it closes back above its preceding
        // base's own high on above-average (vs that base) volume.
        $recentStart = max(0, $n - $recentWindow);
        $anchorIdx = null;
        $baseStartIdx = null;
        $baseEndIdx = null;
        $confirmed = false;

        for ($c = $n - 1; $c >= max(1, $recentStart); $c--) {
            $candidateBaseEnd = $c - 1;
            $candidateBaseStart = max(0, $candidateBaseEnd - $maxBase + 1);
            $len = $candidateBaseEnd - $candidateBaseStart + 1;
            if ($len < $minBase) {
                continue;
            }

            $window = array_slice($bars, $candidateBaseStart, $len);
            $highs = array_map(fn ($b) => (float) ($b['high'] ?? 0), $window);
            $vols = array_map(fn ($b) => (int) ($b['volume'] ?? 0), $window);
            $candidateHigh = max($highs);
            $avgVol = array_sum($vols) / max(1, count($vols));

            $close = (float) ($bars[$c]['close'] ?? 0);
            $volume = (int) ($bars[$c]['volume'] ?? 0);

            if ($candidateHigh > 0 && $close > $candidateHigh && $avgVol > 0 && $volume > $avgVol) {
                $anchorIdx = $c;
                $baseStartIdx = $candidateBaseStart;
                $baseEndIdx = $candidateBaseEnd;
                $confirmed = true;
                break;
            }
        }

        if (! $confirmed) {
            // No confirmed recovery day yet — anchor to the last bar of
            // the single most recent base window, so a still-forming
            // floor is still visible (0 confirmation credit) rather than
            // being rejected outright.
            $baseEndIdx = $n - 1;
            $baseStartIdx = max(0, $baseEndIdx - $maxBase + 1);
            $len = $baseEndIdx - $baseStartIdx + 1;
            if ($len < $minBase) {
                return $this->reject("base too short ({$len} < {$minBase} bars)");
            }
            $anchorIdx = $baseEndIdx;
        }

        $baseLen = $baseEndIdx - $baseStartIdx + 1;
        $baseBars = array_slice($bars, $baseStartIdx, $baseLen);
        $baseHighs = array_map(fn ($b) => (float) ($b['high'] ?? 0), $baseBars);
        $baseLows = array_filter(array_map(fn ($b) => (float) ($b['low'] ?? 0), $baseBars), fn ($v) => $v > 0);
        if (empty($baseLows)) {
            return $this->reject('base has no valid low prices');
        }
        $baseHigh = max($baseHighs);
        $baseLow = min($baseLows);
        if ($baseLow <= 0 || $baseHigh <= 0) {
            return $this->reject('base has invalid high/low prices');
        }
        $rangePct = (($baseHigh - $baseLow) / $baseLow) * 100;

        // ---------------- Prior-decline check (scored, not gated) ----------------
        $lookbackEnd = $baseStartIdx - 1;
        $lookbackStart = max(0, $lookbackEnd - $declineLookback + 1);
        $priorHigh = 0.0;
        if ($lookbackEnd >= 0) {
            $lookbackBars = array_slice($bars, $lookbackStart, $lookbackEnd - $lookbackStart + 1);
            $priorHighs = array_map(fn ($b) => (float) ($b['high'] ?? 0), $lookbackBars);
            $priorHigh = empty($priorHighs) ? 0.0 : max($priorHighs);
        }
        $declinePct = $priorHigh > 0 ? (($priorHigh - $baseLow) / $priorHigh) * 100 : 0.0;

        // ---------------- Floor-touch count ----------------
        $touchThreshold = $baseLow * (1 + $floorTouchTolerancePct / 100);
        $touches = 0;
        $lastTouchIdx = null;
        foreach ($baseBars as $offset => $bar) {
            $idx = $baseStartIdx + $offset;
            $low = (float) ($bar['low'] ?? 0);
            if ($low <= 0 || $low > $touchThreshold) {
                continue;
            }
            if ($lastTouchIdx === null || ($idx - $lastTouchIdx) >= $floorTouchMinGapDays) {
                $touches++;
                $lastTouchIdx = $idx;
            }
        }

        // ---------------- Accumulation ratio (OBV-style proxy) ----------------
        $upVols = [];
        $downVols = [];
        for ($idx = max(1, $baseStartIdx); $idx <= $baseEndIdx; $idx++) {
            $close = (float) ($bars[$idx]['close'] ?? 0);
            $prevClose = (float) ($bars[$idx - 1]['close'] ?? 0);
            $volume = (int) ($bars[$idx]['volume'] ?? 0);
            if ($close > $prevClose) {
                $upVols[] = $volume;
            } elseif ($close < $prevClose) {
                $downVols[] = $volume;
            }
        }
        $avgUpVol = count($upVols) > 0 ? array_sum($upVols) / count($upVols) : 0.0;
        $avgDownVol = count($downVols) > 0 ? array_sum($downVols) / count($downVols) : 0.0;
        $accumulationRatio = $avgDownVol > 0 ? $avgUpVol / $avgDownVol : null;

        // ---------------- Optional bullish RSI/price-divergence bonus ----------------
        // Compare the lowest-close bar of the base window's first half
        // against its second half: if price makes an equal/lower low in
        // the second half while Wilder's RSI (computed only from closes
        // strictly up to and including each low bar, never peeking ahead)
        // makes a *higher* low, that's a bullish divergence.
        $allCloses = array_map(fn ($b) => (float) ($b['close'] ?? 0), $bars);
        $half = intdiv($baseLen, 2);
        $divergence = false;
        if ($half >= 2 && ($baseLen - $half) >= 2) {
            $firstHalf = array_slice($baseBars, 0, $half);
            $secondHalf = array_slice($baseBars, $half);

            $firstHalfCloses = array_map(fn ($b) => (float) ($b['close'] ?? 0), $firstHalf);
            $secondHalfCloses = array_map(fn ($b) => (float) ($b['close'] ?? 0), $secondHalf);

            $firstLowOffset = array_keys($firstHalfCloses, min($firstHalfCloses))[0];
            $secondLowOffset = array_keys($secondHalfCloses, min($secondHalfCloses))[0];

            $firstLowIdx = $baseStartIdx + $firstLowOffset;
            $secondLowIdx = $baseStartIdx + $half + $secondLowOffset;

            $firstLowClose = (float) ($bars[$firstLowIdx]['close'] ?? 0);
            $secondLowClose = (float) ($bars[$secondLowIdx]['close'] ?? 0);

            $rsiFirst = Indicators::rsi(array_slice($allCloses, 0, $firstLowIdx + 1), 14);
            $rsiSecond = Indicators::rsi(array_slice($allCloses, 0, $secondLowIdx + 1), 14);

            $divergence = $rsiFirst !== null && $rsiSecond !== null
                && $secondLowClose <= $firstLowClose
                && $rsiSecond > $rsiFirst;
        }

        // ---------------- 0-7 point "spike rarity" style score ----------------
        $declinePoints = match (true) {
            $declinePct >= $minDeclinePct * 1.5 => 3,
            $declinePct >= $minDeclinePct => 2,
            $declinePct >= $minDeclinePct * 0.5 => 1,
            default => 0,
        };
        $touchPoints = match (true) {
            $touches >= 3 => 2,
            $touches === 2 => 1,
            default => 0,
        };
        $accumulationPoints = match (true) {
            $accumulationRatio !== null && $accumulationRatio >= 1.5 => 2,
            $accumulationRatio !== null && $accumulationRatio > 1.0 => 1,
            default => 0,
        };
        $bonusPoints = ($confirmed ? 1 : 0) + ($divergence ? 1 : 0);
        $spikeRarityPoints = min(7, $declinePoints + $touchPoints + $accumulationPoints + $bonusPoints);

        $spikeRarityDescription = sprintf(
            'Floor reversal: %d touches, decline %s%%, accumulation ratio %s',
            $touches,
            number_format($declinePct, 1),
            $accumulationRatio !== null ? number_format($accumulationRatio, 2).'x' : 'n/a',
        );

        $anchorVol = (int) ($bars[$anchorIdx]['volume'] ?? 0);
        $anchorClose = (float) ($bars[$anchorIdx]['close'] ?? 0);
        $anchorAgeBars = $n - 1 - $anchorIdx;

        $prior52Start = max(0, $anchorIdx - 252);
        $prior52wMax = 0;
        for ($i = $prior52Start; $i < $anchorIdx; $i++) {
            $prior52wMax = max($prior52wMax, (int) ($bars[$i]['volume'] ?? 0));
        }
        $max104w = 0;
        if ($anchorIdx >= 504) {
            for ($i = $anchorIdx - 504; $i < $anchorIdx; $i++) {
                $max104w = max($max104w, (int) ($bars[$i]['volume'] ?? 0));
            }
        }
        $is52w = $prior52wMax > 0 && $anchorVol > $prior52wMax;
        $is104w = $max104w > 0 && $anchorVol > $max104w;

        $threshold = (int) floor($anchorVol * 0.8);
        $prevSpike = null;
        $comparisonStart = max(0, $anchorIdx - 504);
        for ($i = $anchorIdx - 1; $i >= $comparisonStart; $i--) {
            if ((int) ($bars[$i]['volume'] ?? 0) >= $threshold) {
                $prevSpike = $anchorIdx - $i;
                break;
            }
        }

        // ---------------- Quality metrics (shared with the other algorithms) ----------------
        $baseCloses = array_map(fn ($b) => (float) ($b['close'] ?? 0), $baseBars);
        $atrEarly = Indicators::atr(array_slice($baseBars, 0, min(30, $baseLen)), 14);
        $atrLate = Indicators::atr(array_slice($baseBars, -min(30, $baseLen)), 14);
        $atrRatio = ($atrEarly && $atrEarly > 0 && $atrLate !== null) ? $atrLate / $atrEarly : 1.0;
        $slope = Indicators::slope($baseCloses);

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

        $distToBreakout = $baseHigh > 0 ? (($baseHigh - $anchorClose) / $baseHigh) * 100 : 0.0;

        $closesUpToAnchor = array_slice($allCloses, 0, $anchorIdx + 1);
        $sma50 = Indicators::sma($closesUpToAnchor, 50);
        $sma150 = Indicators::sma($closesUpToAnchor, 150);
        $sma200 = Indicators::sma($closesUpToAnchor, 200);
        $maAlignment = $this->maAlignmentString($anchorClose, $sma50, $sma150, $sma200);

        $rs = $this->relativeStrength($bars, $benchmarkBars);

        $result = new StockBuySetupResult(
            symbol: $symbol,
            setupType: $setupType,
            companyName: $context['company_name'] ?? null,
            exchange: $context['exchange'] ?? null,
            marketCap: is_numeric($marketCap) ? (int) $marketCap : null,
            marketCapCategory: $this->marketCapCategory(is_numeric($marketCap) ? (int) $marketCap : null),
            spikeDate: CarbonImmutable::parse($bars[$anchorIdx]['date']),
            spikeVolume: $anchorVol,
            prior52wMaxVolume: $prior52wMax,
            max104wVolume: $max104w,
            is52wHighVolume: $is52w,
            is104wHighVolume: $is104w,
            daysSincePreviousComparableSpike: $prevSpike,
            spikeAgeBars: $anchorAgeBars,
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
            spikeRelativeVolume: $baseAvgVol > 0 ? round($anchorVol / $baseAvgVol, 4) : null,
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
