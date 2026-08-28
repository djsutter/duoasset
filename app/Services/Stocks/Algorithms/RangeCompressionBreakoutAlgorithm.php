<?php

namespace App\Services\Stocks\Algorithms;

use App\Services\Stocks\Algorithms\Concerns\SharedDetectionHelpers;
use App\Services\Stocks\Indicators;
use App\Services\Stocks\StockBuySetupResult;
use Carbon\CarbonImmutable;

/**
 * Pure volatility-squeeze breakout detector — a TTM-Squeeze-style setup,
 * unlike Heartbeat Consolidation + Spike which requires a historically
 * rare (52-week/104-week) high-volume day.
 *
 * Two things make this genuinely different from Heartbeat:
 *
 * 1. The compression trigger is *self-relative*, not a fixed percentage.
 *    The base's range-compression % is compared against the trailing
 *    252-day rolling distribution of range-compression values (using the
 *    same $maxBase-day window, walked bar-by-bar); a "squeeze" only fires
 *    when the current reading sits in the tightest `squeeze_percentile`
 *    (default bottom 20%) of that lookback — i.e. "the tightest this
 *    stock's range has been in about a year", regardless of the absolute
 *    number, rather than a fixed 25%/40% cutoff.
 * 2. The breakout day is simply the first close above the base high with
 *    volume at least `breakout_volume_multiplier` (default 1.3x) the
 *    base's own average volume — no requirement that the day be a 52w/104w
 *    volume record. This is meant to catch tighter, more frequent setups
 *    than Heartbeat's rare-spike bar.
 *
 * No new data source is required — everything is derived from the same
 * daily OHLCV bars already fetched for every algorithm.
 */
class RangeCompressionBreakoutAlgorithm implements BuySetupAlgorithm
{
    use SharedDetectionHelpers;

    public function key(): string
    {
        return 'range_compression_breakout';
    }

    public function label(): string
    {
        return 'Range compression breakout';
    }

    public function detect(array $bars, array $benchmarkBars, array $context, array $typeConfig, string $setupType): ?StockBuySetupResult
    {
        $this->lastRejectionReason = null;

        $minBase = (int) ($typeConfig['min_base_days'] ?? 45);
        $maxBase = (int) ($typeConfig['max_base_days'] ?? 120);
        $squeezePercentile = (float) ($typeConfig['squeeze_percentile'] ?? 20.0);
        $breakoutVolumeMultiplier = (float) ($typeConfig['breakout_volume_multiplier'] ?? 1.3);
        $recentWindow = (int) ($typeConfig['recent_spike_window_days'] ?? 60);

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

        // ---------------- Rolling self-relative compression scan ----------------
        // Build the comparison population from up to the trailing ~252
        // trading days (widened by $maxBase so the earliest window is still
        // a full base), walking one maxBase-day window per bar. The
        // "squeeze" is then the most recent base — among only those ending
        // within the last $recentWindow bars — whose compression sits in
        // the tightest configured percentile of that trailing-year
        // population, i.e. genuinely rare for *this* stock, not just an
        // arbitrary fixed cutoff.
        $populationLookback = min($n, 252 + $maxBase);
        $populationStart = max(0, $n - $populationLookback);

        $readings = [];
        for ($endIdx = $populationStart + $minBase - 1; $endIdx < $n; $endIdx++) {
            $startIdx = max(0, $endIdx - $maxBase + 1);
            $len = $endIdx - $startIdx + 1;
            if ($len < $minBase) {
                continue;
            }

            $window = array_slice($bars, $startIdx, $len);
            $highs = array_map(fn ($b) => (float) ($b['high'] ?? 0), $window);
            $lows = array_filter(array_map(fn ($b) => (float) ($b['low'] ?? 0), $window), fn ($v) => $v > 0);
            if (empty($lows)) {
                continue;
            }
            $high = max($highs);
            $low = min($lows);
            if ($low <= 0 || $high <= 0) {
                continue;
            }

            $readings[] = [
                'endIdx' => $endIdx,
                'startIdx' => $startIdx,
                'len' => $len,
                'high' => $high,
                'low' => $low,
                'rangePct' => (($high - $low) / $low) * 100,
            ];
        }

        if (empty($readings)) {
            return $this->reject("base too short ({$maxBase} < {$minBase} bars)");
        }

        $population = array_column($readings, 'rangePct');
        sort($population);

        // ---------------- Breakout-day-first search ----------------
        // Scan candidate breakout days from most recent backward. For each
        // candidate day $b, the base is the maxBase-day window strictly
        // *before* it (never including $b itself, so the breakout bar can
        // never contaminate its own base's range reading). The candidate
        // is accepted once its preceding window is a genuine historic
        // squeeze (percentile-ranked against the trailing-year population
        // above) AND $b closes above that window's high on at least
        // $breakoutVolumeMultiplier x the window's average volume.
        $recentThreshold = $n - $recentWindow;
        $breakoutIdx = null;
        $baseWindow = null;

        for ($b = $n - 1; $b >= max(1, $recentThreshold); $b--) {
            $baseEndIdx = $b - 1;
            $baseStartIdx = max(0, $baseEndIdx - $maxBase + 1);
            $len = $baseEndIdx - $baseStartIdx + 1;
            if ($len < $minBase) {
                continue;
            }

            $window = array_slice($bars, $baseStartIdx, $len);
            $highs = array_map(fn ($bar) => (float) ($bar['high'] ?? 0), $window);
            $lows = array_filter(array_map(fn ($bar) => (float) ($bar['low'] ?? 0), $window), fn ($v) => $v > 0);
            if (empty($lows)) {
                continue;
            }
            $candidateHigh = max($highs);
            $candidateLow = min($lows);
            if ($candidateLow <= 0 || $candidateHigh <= 0) {
                continue;
            }

            $candidateRangePct = (($candidateHigh - $candidateLow) / $candidateLow) * 100;
            if ($this->percentileRank($candidateRangePct, $population) > $squeezePercentile) {
                continue;
            }

            $vols = array_map(fn ($bar) => (int) ($bar['volume'] ?? 0), $window);
            $avgVol = array_sum($vols) / max(1, count($vols));
            $close = (float) ($bars[$b]['close'] ?? 0);
            $volume = (int) ($bars[$b]['volume'] ?? 0);

            if ($close > $candidateHigh && $avgVol > 0 && $volume >= $avgVol * $breakoutVolumeMultiplier) {
                $breakoutIdx = $b;
                $baseWindow = [
                    'endIdx' => $baseEndIdx,
                    'startIdx' => $baseStartIdx,
                    'len' => $len,
                    'high' => $candidateHigh,
                    'low' => $candidateLow,
                    'rangePct' => $candidateRangePct,
                ];
                break;
            }
        }

        if ($baseWindow === null) {
            // No confirmed breakout out of a genuine historic squeeze.
            // Fall back to the single most recent base window (regardless
            // of percentile) so a still-compressing setup is still visible,
            // scored with 0 rarity points, rather than disappearing.
            $baseWindow = $readings[count($readings) - 1];
        }

        $baseStartIdx = $baseWindow['startIdx'];
        $baseEndIdx = $baseWindow['endIdx'];
        $baseHigh = $baseWindow['high'];
        $baseLow = $baseWindow['low'];
        $rangePct = $baseWindow['rangePct'];
        $baseLen = $baseWindow['len'];
        $baseBars = array_slice($bars, $baseStartIdx, $baseLen);
        $baseVols = array_map(fn ($b) => (int) ($b['volume'] ?? 0), $baseBars);
        $baseAvgVol = array_sum($baseVols) / max(1, count($baseVols));

        // No confirmed breakout yet — anchor to the last bar in the base
        // window so the still-compressing setup is visible (0 breakout-day
        // volume multiplier credit), rather than rejecting outright.
        $spikeIdx = $breakoutIdx ?? $baseEndIdx;
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

        $volumeMultiple = $baseAvgVol > 0 ? round($spikeVol / $baseAvgVol, 2) : null;
        $percentileDescription = number_format(100 - $this->percentileRank($rangePct, $population), 0);

        if ($breakoutIdx !== null) {
            $spikeRarityDescription = "Range-compression breakout (tighter than {$percentileDescription}% of the last year, "
                .($volumeMultiple !== null ? "{$volumeMultiple}x avg base volume" : 'confirmed breakout')
                .')';
            // Reuse the 0-7 rarity scale so this plugs into the existing
            // spike_rarity score component: a confirmed breakout on a
            // historic-relative squeeze earns strong (not maximal) points,
            // since — unlike Heartbeat — no record volume day is required.
            $spikeRarityPoints = $this->percentileRank($rangePct, $population) <= 10 ? 5 : 4;
        } else {
            $spikeRarityDescription = "Squeeze forming (tighter than {$percentileDescription}% of the last year), no confirmed breakout yet";
            $spikeRarityPoints = 0;
        }

        // Days since a comparable prior breakout-strength volume day.
        $threshold = (int) floor($spikeVol * 0.8);
        $prevSpike = null;
        $comparisonStart = max(0, $spikeIdx - 504);
        for ($i = $spikeIdx - 1; $i >= $comparisonStart; $i--) {
            if ((int) ($bars[$i]['volume'] ?? 0) >= $threshold) {
                $prevSpike = $spikeIdx - $i;
                break;
            }
        }

        // ---------------- Quality metrics (shared with Heartbeat) ----------------
        $closes = array_map(fn ($b) => (float) ($b['close'] ?? 0), $baseBars);
        $atrEarly = Indicators::atr(array_slice($baseBars, 0, min(30, $baseLen)), 14);
        $atrLate = Indicators::atr(array_slice($baseBars, -min(30, $baseLen)), 14);
        $atrRatio = ($atrEarly && $atrEarly > 0 && $atrLate !== null) ? $atrLate / $atrEarly : 1.0;
        $slope = Indicators::slope($closes);

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

    /**
     * Standard percentile rank: the percentage of $sortedPopulation that is
     * strictly less than $value. The population minimum always ranks 0
     * (nothing is smaller), regardless of how many ties sit at that
     * minimum — unlike a "<=" based rank, which would misrank a value tied
     * with most of the population as high just because many other values
     * are <= it. Lower rank = tighter/rarer for our range-compression use.
     *
     * @param  array<int, float>  $sortedPopulation
     */
    private function percentileRank(float $value, array $sortedPopulation): float
    {
        $count = count($sortedPopulation);
        if ($count === 0) {
            return 0.0;
        }

        $countBelow = 0;
        foreach ($sortedPopulation as $v) {
            if ($v < $value) {
                $countBelow++;
            }
        }

        return ($countBelow / $count) * 100;
    }
}
