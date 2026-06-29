<?php

namespace App\Services\Stocks;

use App\Services\MarketData\MarketCap;
use Carbon\CarbonImmutable;

/**
 * Pure detection logic for the Stock Buy Setup scanner.
 *
 * Given an ascending-date series of OHLCV bars for a single symbol,
 * evaluate() returns a StockBuySetupResult when:
 *   1. A high-volume spike sits inside the configured recent window
 *      (default: last 42 trading days), AND
 *   2. The bars preceding the spike form a tight consolidation base
 *      (range compression, ATR contraction, near-zero slope).
 *
 * No DB / no HTTP — everything is in-memory so the scanner is fully
 * deterministic and easy to unit-test with synthetic series.
 */
class StockBuySetupScanner
{
    /**
     * @param  array<int, array{date:string, open:?float, high:?float, low:?float, close:?float, volume:?int}>  $bars
     *                                                                                                                 Daily bars, ascending by date. Recommend ≥ 252 bars (1y).
     * @param  array<int, array<string, mixed>>  $benchmarkBars  Same shape (e.g. SPY) for RS.
     * @param  array{symbol?:string, company_name?:string, exchange?:string, market_cap?:int}  $context
     */
    public function evaluate(array $bars, array $benchmarkBars = [], array $context = []): ?StockBuySetupResult
    {
        $cfg = config('market_data.buy_setup_scanner', []);
        $recentWindow = (int) ($cfg['recent_spike_window_days'] ?? 42);
        $maxSpikeAge = (int) ($cfg['max_spike_age_days'] ?? 84);
        $minHistory = (int) ($cfg['history_lookback_days'] ?? 504);
        $minBase = (int) ($cfg['min_base_days'] ?? 60);
        $maxBase = (int) ($cfg['max_base_days'] ?? 120);
        $maxRangePct = (float) ($cfg['max_range_compression_pct'] ?? 25);
        $maxAtrRatio = (float) ($cfg['max_atr_ratio'] ?? 0.85);

        $bars = array_values($bars);
        $n = count($bars);
        // Need at least 252 bars (~52 weeks) for the comparison universe.
        if ($n < 252) {
            return null;
        }

        $symbol = strtoupper((string) ($context['symbol'] ?? ''));

        // ---------------- Spike detection ----------------
        // Find the highest-volume bar inside the recent window.
        $spikeIdx = null;
        $spikeVol = 0;
        $start = max(0, $n - $recentWindow);
        for ($i = $start; $i < $n; $i++) {
            $v = (int) ($bars[$i]['volume'] ?? 0);
            if ($v > $spikeVol) {
                $spikeVol = $v;
                $spikeIdx = $i;
            }
        }
        if ($spikeIdx === null || $spikeVol <= 0) {
            return null;
        }

        // Spike must not be older than max_spike_age_days from the most recent bar.
        if (($n - 1 - $spikeIdx) > $maxSpikeAge) {
            return null;
        }

        // Compare against the prior 252 bars (excluding the spike itself).
        $priorStart = max(0, $spikeIdx - 252);
        $prior52wMax = 0;
        for ($i = $priorStart; $i < $spikeIdx; $i++) {
            $v = (int) ($bars[$i]['volume'] ?? 0);
            if ($v > $prior52wMax) {
                $prior52wMax = $v;
            }
        }

        // Gate: spike must exceed the prior 52w maximum.
        if ($spikeVol <= $prior52wMax) {
            return null;
        }

        // 104-week (504 bars) high-volume bonus.
        $is104w = false;
        $max104w = 0;
        if ($spikeIdx >= 504) {
            $start104 = $spikeIdx - 504;
            for ($i = $start104; $i < $spikeIdx; $i++) {
                $v = (int) ($bars[$i]['volume'] ?? 0);
                if ($v > $max104w) {
                    $max104w = $v;
                }
            }
            $is104w = $spikeVol > $max104w;
        }

        // Days since previous comparable spike (>= 80% of current spike vol),
        // measured in bar indices (trading days).
        $threshold = (int) floor($spikeVol * 0.8);
        $prevSpike = null;
        for ($i = $spikeIdx - 1; $i >= $priorStart; $i--) {
            if ((int) ($bars[$i]['volume'] ?? 0) >= $threshold) {
                $prevSpike = $spikeIdx - $i;
                break;
            }
        }

        // ---------------- Consolidation base ----------------
        // Base = the $maxBase bars immediately preceding the spike (clamped).
        $baseEndIdx = $spikeIdx - 1;
        $baseStartIdx = max(0, $baseEndIdx - $maxBase + 1);
        $baseLen = $baseEndIdx - $baseStartIdx + 1;
        if ($baseLen < $minBase) {
            return null;
        }

        $baseBars = array_slice($bars, $baseStartIdx, $baseLen);
        $closes = array_map(fn ($b) => (float) ($b['close'] ?? 0), $baseBars);
        $highs = array_map(fn ($b) => (float) ($b['high'] ?? 0), $baseBars);
        $lows = array_filter(
            array_map(fn ($b) => (float) ($b['low'] ?? 0), $baseBars),
            fn ($v) => $v > 0,
        );
        if (empty($lows)) {
            return null;
        }

        $baseHigh = max($highs);
        $baseLow = min($lows);
        if ($baseLow <= 0 || $baseHigh <= 0) {
            return null;
        }
        $rangePct = (($baseHigh - $baseLow) / $baseLow) * 100;
        if ($rangePct > $maxRangePct) {
            return null;
        }

        // ATR contraction = ATR of last 14 days of base vs first 14 days.
        $atrEarly = Indicators::atr(array_slice($baseBars, 0, min(30, $baseLen)), 14);
        $atrLate = Indicators::atr(array_slice($baseBars, -min(30, $baseLen)), 14);
        $atrRatio = ($atrEarly && $atrEarly > 0 && $atrLate !== null)
            ? $atrLate / $atrEarly
            : 1.0;
        if ($atrRatio > $maxAtrRatio) {
            return null;
        }

        $slope = Indicators::slope($closes);

        // Volume dry-up: average base volume / average prior 60 days before base.
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

        // ---------------- Quality metrics ----------------
        $spikeClose = (float) ($bars[$spikeIdx]['close'] ?? 0);
        $distToBreakout = $baseHigh > 0
            ? (($baseHigh - $spikeClose) / $baseHigh) * 100
            : 0.0;

        // Moving averages up to and including the spike bar.
        $closesUpToSpike = [];
        for ($i = 0; $i <= $spikeIdx; $i++) {
            $closesUpToSpike[] = (float) ($bars[$i]['close'] ?? 0);
        }
        $sma50 = Indicators::sma($closesUpToSpike, 50);
        $sma150 = Indicators::sma($closesUpToSpike, 150);
        $sma200 = Indicators::sma($closesUpToSpike, 200);

        $maAlignment = $this->maAlignmentString($spikeClose, $sma50, $sma150, $sma200);

        // Relative strength: 6-month return of symbol vs benchmark.
        $rs = $this->relativeStrength($bars, $benchmarkBars);

        $marketCap = $context['market_cap'] ?? null;
        $result = new StockBuySetupResult(
            symbol: $symbol,
            companyName: $context['company_name'] ?? null,
            exchange: $context['exchange'] ?? null,
            marketCap: is_numeric($marketCap) ? (int) $marketCap : null,
            marketCapCategory: $this->marketCapCategory(is_numeric($marketCap) ? (int) $marketCap : null),
            spikeDate: CarbonImmutable::parse($bars[$spikeIdx]['date']),
            spikeVolume: $spikeVol,
            prior52wMaxVolume: $prior52wMax,
            max104wVolume: $max104w,
            is52wHighVolume: $spikeVol > $prior52wMax,
            is104wHighVolume: $is104w,
            daysSincePreviousComparableSpike: $prevSpike,
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
        );

        $result->reasonSummary = $this->reasonSummary($result);

        return $result;
    }

    /**
     * Classify market cap into small / mid / large / mega buckets.
     */
    public function marketCapCategory(?int $cap): string
    {
        if ($cap === null || $cap <= 0) {
            return 'unknown';
        }
        if ($cap >= 200_000_000_000) {
            return 'mega';
        }
        if ($cap >= 10_000_000_000) {
            return 'large';
        }
        if ($cap >= 2_000_000_000) {
            return 'mid';
        }

        return 'small';
    }

    private function maAlignmentString(float $price, ?float $sma50, ?float $sma150, ?float $sma200): string
    {
        if ($sma50 === null || $sma150 === null || $sma200 === null) {
            return 'insufficient_history';
        }

        $parts = [];
        if ($sma50 > $sma150 && $sma150 > $sma200) {
            $parts[] = '50>150>200';
        } elseif ($sma50 > $sma200) {
            $parts[] = '50>200';
        } else {
            $parts[] = 'mixed';
        }
        $parts[] = $price > $sma50 ? 'price>50' : 'price<=50';

        return implode(', ', $parts);
    }

    /**
     * 6-month (~126 bar) return of subject vs benchmark, expressed as
     * (subject_return - benchmark_return) * 100. Returns null when the
     * benchmark series is unavailable or too short.
     */
    private function relativeStrength(array $bars, array $benchmark): ?float
    {
        if (count($benchmark) < 126 || count($bars) < 126) {
            return null;
        }

        $sNow = (float) ($bars[count($bars) - 1]['close'] ?? 0);
        $sThen = (float) ($bars[count($bars) - 126]['close'] ?? 0);
        $bNow = (float) ($benchmark[count($benchmark) - 1]['close'] ?? 0);
        $bThen = (float) ($benchmark[count($benchmark) - 126]['close'] ?? 0);

        if ($sThen <= 0 || $bThen <= 0) {
            return null;
        }

        $sRet = ($sNow - $sThen) / $sThen;
        $bRet = ($bNow - $bThen) / $bThen;

        return round(($sRet - $bRet) * 100, 4);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    private function reasonSummary(StockBuySetupResult $r): string
    {
        $bits = [];
        $bits[] = $r->is104wHighVolume
            ? '104w high-volume spike'
            : ($r->is52wHighVolume ? '52w high-volume spike' : 'high-volume spike');
        $bits[] = "base {$r->baseDurationDays}d (range ".number_format($r->rangeCompressionPct, 1).'%)';
        $bits[] = 'ATR ratio '.number_format($r->atrContractionRatio, 2);
        if ($r->relativeStrengthScore !== null) {
            $bits[] = 'RS '.($r->relativeStrengthScore >= 0 ? '+' : '').number_format($r->relativeStrengthScore, 1);
        }
        $bits[] = 'dist to bo '.number_format($r->distanceToBreakoutPct, 1).'%';

        return implode('; ', $bits);
    }
}
