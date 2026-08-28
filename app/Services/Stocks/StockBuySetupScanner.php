<?php

namespace App\Services\Stocks;

use App\Services\Stocks\Algorithms\BuySetupAlgorithmRegistry;
use Carbon\CarbonImmutable;

/**
 * Pure detection logic for the Stock Buy Setup scanner.
 *
 * Given an ascending-date series of OHLCV bars for a single symbol,
 * evaluate() scores every sufficiently liquid, adequately capitalized symbol.
 * A high-volume spike is a probability bonus, not a hard qualification gate.
 * Spike rarity is searched no farther back than 104 weeks (504 bars).
 *
 * No DB / no HTTP — everything is in-memory so the scanner is fully
 * deterministic and easy to unit-test with synthetic series.
 *
 * evaluate() below is the "Heartbeat Consolidation + Spike" algorithm. It
 * stays in this class (rather than moving into
 * Algorithms\HeartbeatConsolidationSpikeAlgorithm) to preserve its
 * extensive existing test coverage and its direct callers. The other three
 * built-in algorithms (Range Compression Breakout, Floor Reversal /
 * Accumulation, Early Breakout Follow-Through) live under
 * App\Services\Stocks\Algorithms and are dispatched via
 * BuySetupAlgorithmRegistry from evaluateAll() below, based on each setup
 * type's configured `algorithm` key.
 */
class StockBuySetupScanner
{
    private ?string $lastRejectionReason = null;

    /**
     * Human-readable reason for the most recent non-match. Useful for
     * Artisan verbose/debug scans.
     */
    public function lastRejectionReason(): ?string
    {
        return $this->lastRejectionReason;
    }

    private function reject(string $reason): null
    {
        $this->lastRejectionReason = $reason;

        return null;
    }

    /**
     * Run all enabled buy setup detectors for a symbol and return one result
     * per matched setup type.
     *
     * Each setup type selects which algorithm actually runs via its own
     * `algorithm` config key (see BuySetupAlgorithmRegistry), independent
     * of the setup type's own key/label — a setup type with no (or an
     * unknown) `algorithm` configured falls back to the original Heartbeat
     * Consolidation + Spike detector below, so existing configs behave
     * exactly as before until an admin explicitly picks a different one.
     *
     * @param  array<int, array{date:string, open:?float, high:?float, low:?float, close:?float, volume:?int}>  $bars
     * @param  array<int, array<string, mixed>>  $benchmarkBars
     * @param  array<string, mixed>  $context
     * @return array<int, StockBuySetupResult>
     */
    public function evaluateAll(array $bars, array $benchmarkBars = [], array $context = []): array
    {
        $configService = app(BuySetupConfigService::class);
        $types = $configService->getSetupTypes();

        $results = [];

        foreach ($types as $key => $typeConfig) {
            if (! (bool) ($typeConfig['enabled'] ?? false)) {
                continue;
            }

            $algorithm = BuySetupAlgorithmRegistry::resolve($typeConfig['algorithm'] ?? $key);
            $result = $algorithm->detect($bars, $benchmarkBars, $context, $typeConfig, $key);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array{date:string, open:?float, high:?float, low:?float, close:?float, volume:?int}>  $bars
     *                                                                                                                 Daily bars, ascending by date. Recommend ≥ 252 bars (1y).
     * @param  array<int, array<string, mixed>>  $benchmarkBars  Same shape (e.g. SPY) for RS.
     * @param  array{symbol?:string, company_name?:string, exchange?:string, market_cap?:int}  $context
     * @param  array<string, mixed>|null  $typeConfig
     */
    public function evaluate(array $bars, array $benchmarkBars = [], array $context = [], ?array $typeConfig = null, ?string $setupType = null): ?StockBuySetupResult
    {
        $this->lastRejectionReason = null;
        $configService = app(BuySetupConfigService::class);
        $resolvedType = $setupType ?? ($typeConfig['key'] ?? StockBuySetupResult::TYPE_HEARTBEAT_CONSOLIDATION_SPIKE);
        $cfg = $typeConfig ?? $configService->getSetupType($resolvedType);

        $recentWindow = (int) ($cfg['recent_spike_window_days'] ?? 60);
        $maxSpikeLookback = min(504, max(1, (int) ($cfg['max_spike_age_days'] ?? $cfg['spike_lookback_days'] ?? 84)));
        $minHistory = (int) ($cfg['history_lookback_days'] ?? $configService->getHistoryLookbackDays());
        $minBase = (int) ($cfg['min_base_days'] ?? 45);
        $maxBase = (int) ($cfg['max_base_days'] ?? 120);
        $maxRangePct = (float) ($cfg['max_range_compression_pct'] ?? 40);
        $maxAtrRatio = (float) ($cfg['max_atr_ratio'] ?? 0.85);

        $bars = array_values($bars);
        $n = count($bars);
        // Need at least 252 bars (~52 weeks) for the comparison universe.
        if ($n < 252) {
            return $this->reject("insufficient history ({$n} < 252 bars)");
        }

        $symbol = strtoupper((string) ($context['symbol'] ?? ''));
        $marketCap = $context['market_cap'] ?? null;
        // Market-cap eligibility is configurable per setup type (min_market_cap /
        // max_market_cap), inclusive on both ends, so a stock can qualify for
        // one setup type while being excluded from another.
        $minMarketCap = (int) ($cfg['min_market_cap'] ?? BuySetupConfigService::DEFAULT_MIN_MARKET_CAP);
        $maxMarketCap = (int) ($cfg['max_market_cap'] ?? BuySetupConfigService::DEFAULT_MAX_MARKET_CAP);
        if (is_numeric($marketCap)) {
            $marketCapInt = (int) $marketCap;
            if ($marketCapInt < $minMarketCap) {
                return $this->reject("market cap below setup minimum ({$minMarketCap})");
            }
            if ($marketCapInt > $maxMarketCap) {
                return $this->reject("market cap above setup maximum ({$maxMarketCap})");
            }
        }

        // ---------------- Spike detection ----------------
        // A spike is now a scored bonus, never a hard qualification gate.
        // Search no farther back than 104 weeks (504 trading bars), then keep
        // the candidate with the strongest rarity/recency score. When no bar
        // qualifies as a 52w/104w high-volume event, retain the highest-volume
        // recent bar as the base anchor and award zero Spike rarity points.
        $spikeIdx = null;
        $spikeVol = 0;
        $prior52wMax = 0;
        $max104w = 0;
        $is52w = false;
        $is104w = false;
        $spikeAgeBars = null;
        $spikeRarityPoints = 0;
        $spikeRarityDescription = 'No qualifying spike in the last 104 weeks';

        $searchStart = max($minBase, $n - 1 - $maxSpikeLookback);
        $bestRank = [-1, -1, -1];

        for ($candidateIdx = $searchStart; $candidateIdx < $n; $candidateIdx++) {
            $candidateVol = (int) ($bars[$candidateIdx]['volume'] ?? 0);
            if ($candidateVol <= 0) {
                continue;
            }

            $candidateAge = $n - 1 - $candidateIdx;
            $candidatePrior52Start = max(0, $candidateIdx - 252);
            $candidatePrior52Max = 0;
            for ($i = $candidatePrior52Start; $i < $candidateIdx; $i++) {
                $candidatePrior52Max = max($candidatePrior52Max, (int) ($bars[$i]['volume'] ?? 0));
            }

            $candidatePrior104Max = 0;
            if ($candidateIdx >= 504) {
                for ($i = $candidateIdx - 504; $i < $candidateIdx; $i++) {
                    $candidatePrior104Max = max($candidatePrior104Max, (int) ($bars[$i]['volume'] ?? 0));
                }
            }

            $candidateIs52w = $candidatePrior52Max > 0 && $candidateVol > $candidatePrior52Max;
            $candidateIs104w = $candidatePrior104Max > 0 && $candidateVol > $candidatePrior104Max;
            $candidatePoints = $this->spikeRarityPoints($candidateAge, $candidateIs52w, $candidateIs104w);
            $rank = [$candidatePoints, -$candidateAge, $candidateVol];

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $spikeIdx = $candidateIdx;
                $spikeVol = $candidateVol;
                $prior52wMax = $candidatePrior52Max;
                $max104w = $candidatePrior104Max;
                $is52w = $candidateIs52w;
                $is104w = $candidateIs104w;
                $spikeAgeBars = $candidateAge;
                $spikeRarityPoints = $candidatePoints;
            }
        }

        // With no qualifying record-volume event, anchor the remaining setup
        // calculations to the highest-volume bar in the configured recent
        // window. This preserves current setup relevance while scoring the
        // spike component at zero.
        if ($spikeRarityPoints === 0) {
            $fallbackStart = max(0, $n - max(1, $recentWindow));
            for ($i = $fallbackStart; $i < $n; $i++) {
                $volume = (int) ($bars[$i]['volume'] ?? 0);
                if ($volume > $spikeVol || $spikeIdx === null) {
                    $spikeIdx = $i;
                    $spikeVol = $volume;
                }
            }

            if ($spikeIdx === null) {
                $spikeIdx = $n - 1;
                $spikeVol = (int) ($bars[$spikeIdx]['volume'] ?? 0);
            }

            $spikeAgeBars = $n - 1 - $spikeIdx;
            $priorStart = max(0, $spikeIdx - 252);
            $prior52wMax = 0;
            for ($i = $priorStart; $i < $spikeIdx; $i++) {
                $prior52wMax = max($prior52wMax, (int) ($bars[$i]['volume'] ?? 0));
            }
            $max104w = 0;
            if ($spikeIdx >= 504) {
                for ($i = $spikeIdx - 504; $i < $spikeIdx; $i++) {
                    $max104w = max($max104w, (int) ($bars[$i]['volume'] ?? 0));
                }
            }
            $is52w = false;
            $is104w = false;
        }

        $spikeRarityDescription = match (true) {
            $spikeRarityPoints <= 0 => 'No qualifying spike in the last 104 weeks',
            $is104w => "104-week high-volume spike ({$spikeAgeBars} bars ago)",
            $is52w => "52-week high-volume spike ({$spikeAgeBars} bars ago)",
            default => "High-volume spike ({$spikeAgeBars} bars ago)",
        };

        // Days since previous comparable spike (>= 80% of current spike vol).
        $threshold = (int) floor($spikeVol * 0.8);
        $prevSpike = null;
        $comparisonStart = max(0, $spikeIdx - 504);
        for ($i = $spikeIdx - 1; $i >= $comparisonStart; $i--) {
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
            return $this->reject("base too short ({$baseLen} < {$minBase} bars)");
        }

        $baseBars = array_slice($bars, $baseStartIdx, $baseLen);
        $closes = array_map(fn ($b) => (float) ($b['close'] ?? 0), $baseBars);
        $highs = array_map(fn ($b) => (float) ($b['high'] ?? 0), $baseBars);
        $lows = array_filter(
            array_map(fn ($b) => (float) ($b['low'] ?? 0), $baseBars),
            fn ($v) => $v > 0,
        );
        if (empty($lows)) {
            return $this->reject('base has no valid low prices');
        }

        $baseHigh = max($highs);
        $baseLow = min($lows);
        if ($baseLow <= 0 || $baseHigh <= 0) {
            return $this->reject('base has invalid high/low prices');
        }
        $rangePct = (($baseHigh - $baseLow) / $baseLow) * 100;
        // Range compression is intentionally scored, not used as a hard gate.
        // This lets the UI show near-misses such as a 26.9% base when the
        // preferred target is 25%, ranked lower instead of disappearing.

        // ATR contraction = ATR of last 14 days of base vs first 14 days.
        $atrEarly = Indicators::atr(array_slice($baseBars, 0, min(30, $baseLen)), 14);
        $atrLate = Indicators::atr(array_slice($baseBars, -min(30, $baseLen)), 14);
        $atrRatio = ($atrEarly && $atrEarly > 0 && $atrLate !== null)
            ? $atrLate / $atrEarly
            : 1.0;
        // ATR contraction is also scored instead of hard-rejected. A high
        // ratio simply receives fewer/no points in the setup score.

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

        $result = new StockBuySetupResult(
            symbol: $symbol,
            setupType: $resolvedType,
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

        $result->reasonSummary = $this->reasonSummary($result, $this->nullableFloat($context['prior_year_revenue'] ?? null));

        return $result;
    }

    /**
     * Score spike rarity and recency. Events older than the 104-week search
     * horizon are never inspected and therefore receive zero points.
     */
    private function spikeRarityPoints(int $ageBars, bool $is52w, bool $is104w): int
    {
        if ($ageBars > 504) {
            return 0;
        }

        if ($is104w) {
            return match (true) {
                $ageBars <= 20 => 7,
                $ageBars <= 40 => 6,
                $ageBars <= 60 => 5,
                $ageBars <= 90 => 4,
                default => 3,
            };
        }

        if ($is52w) {
            return match (true) {
                $ageBars <= 40 => 3,
                $ageBars <= 90 => 2,
                default => 1,
            };
        }

        return 0;
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
        if ($cap >= 300_000_000) {
            return 'small';
        }

        return 'micro';
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

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    /**
     * Builds the Reason text as two short, labelled paragraphs so it reads
     * better in the modal card: the existing technical setup description
     * under "Technical:", followed by the newer fundamental scoring
     * metrics (when enabled/available) under "Fundamentals:".
     */
    private function reasonSummary(StockBuySetupResult $r, ?float $priorYearRevenue = null): string
    {
        $technicalBits = [];
        $technicalBits[] = $r->spikeRarityDescription;
        $technicalBits[] = "base {$r->baseDurationDays}d (range ".number_format($r->rangeCompressionPct, 1).'%)';
        $technicalBits[] = 'ATR ratio '.number_format($r->atrContractionRatio, 2);
        if ($r->relativeStrengthScore !== null) {
            $technicalBits[] = 'RS '.($r->relativeStrengthScore >= 0 ? '+' : '').number_format($r->relativeStrengthScore, 1);
        }
        $technicalBits[] = 'dist to bo '.number_format($r->distanceToBreakoutPct, 1).'%';

        $paragraphs = ["Technical:\n".implode('; ', $technicalBits).'.'];

        $fundamentalBits = $this->reasonFundamentalBits($r, $priorYearRevenue);
        if ($fundamentalBits !== []) {
            $paragraphs[] = "Fundamentals:\n".implode('; ', array_map('ucfirst', $fundamentalBits)).'.';
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Builds the newer fundamental scoring metric bits (earnings
     * acceleration, sales acceleration, operating margin expansion) used
     * for the "Fundamentals:" paragraph of the Reason text.
     *
     * Reuses the points/max/value already computed by
     * StockBuySetupScorer::breakdown() — the same source of truth backing
     * the Score breakdown and Fundamentals panels — so nothing is
     * recalculated here. A metric is skipped when it is disabled for the
     * setup type (configured weight of 0) or when its value is unavailable.
     *
     * @return array<int, string>
     */
    private function reasonFundamentalBits(StockBuySetupResult $r, ?float $priorYearRevenue): array
    {
        $breakdown = app(StockBuySetupScorer::class)->breakdown($r, $r->setupType, $priorYearRevenue);

        $labels = [
            'earnings_acceleration' => 'earnings accel',
            'sales_acceleration' => 'sales accel',
            'operating_margin_expansion' => 'operating margin expansion',
            'fcf_margin_expansion' => 'FCF margin expansion',
        ];

        $rawValues = [
            'earnings_acceleration' => $r->earningsAcceleration,
            'sales_acceleration' => $r->salesAcceleration,
            'operating_margin_expansion' => $r->operatingMarginExpansionBps,
            'fcf_margin_expansion' => $r->fcfMarginExpansionBps,
        ];

        $bits = [];
        foreach ($labels as $key => $label) {
            $component = $breakdown[$key] ?? null;
            if (! $component || (int) ($component['max'] ?? 0) <= 0) {
                continue;
            }

            $value = (string) ($component['value'] ?? '');
            if ($value === '' || $value === 'n/a') {
                continue;
            }

            $raw = $rawValues[$key] ?? null;
            if ($raw !== null && $raw >= 0 && ! str_starts_with($value, '+') && ! str_starts_with($value, '-')) {
                $value = '+'.$value;
            }

            $bits[] = "{$label} {$value} ({$component['points']}/{$component['max']})";
        }

        return $bits;
    }
}
