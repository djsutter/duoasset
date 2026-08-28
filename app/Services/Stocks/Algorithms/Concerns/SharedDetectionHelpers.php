<?php

namespace App\Services\Stocks\Algorithms\Concerns;

use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupResult;
use App\Services\Stocks\StockBuySetupScorer;

/**
 * Detection logic shared by every BuySetupAlgorithm implementation:
 * market-cap eligibility gating, market-cap categorization, moving-average
 * alignment, relative strength vs a benchmark, and the "Fundamentals:"
 * reason-summary paragraph (reused as-is from StockBuySetupScorer so the
 * Reason text and the Score Breakdown panel never disagree).
 *
 * Mirrors the private helpers in StockBuySetupScanner so new algorithms
 * don't have to re-derive them, while StockBuySetupScanner::evaluate()
 * itself is left untouched to preserve its existing test coverage.
 */
trait SharedDetectionHelpers
{
    private ?string $lastRejectionReason = null;

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
     * Market-cap eligibility is configurable per setup type (min_market_cap
     * / max_market_cap), inclusive on both ends. Returns a rejection reason
     * string when the (numeric) market cap falls outside the configured
     * range, or null when eligible / market cap is unknown.
     *
     * @param  array<string, mixed>  $typeConfig
     */
    private function marketCapRejectionReason(mixed $marketCap, array $typeConfig): ?string
    {
        if (! is_numeric($marketCap)) {
            return null;
        }

        $minMarketCap = (int) ($typeConfig['min_market_cap'] ?? BuySetupConfigService::DEFAULT_MIN_MARKET_CAP);
        $maxMarketCap = (int) ($typeConfig['max_market_cap'] ?? BuySetupConfigService::DEFAULT_MAX_MARKET_CAP);
        $marketCapInt = (int) $marketCap;

        if ($marketCapInt < $minMarketCap) {
            return "market cap below setup minimum ({$minMarketCap})";
        }
        if ($marketCapInt > $maxMarketCap) {
            return "market cap above setup maximum ({$maxMarketCap})";
        }

        return null;
    }

    /**
     * Classify market cap into micro / small / mid / large / mega buckets.
     */
    private function marketCapCategory(?int $cap): string
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
     * Builds the "Fundamentals:" reason paragraph, reusing the points/max
     * /value already computed by StockBuySetupScorer::breakdown() — the
     * same source of truth backing the Score Breakdown panel — so nothing
     * is recalculated here and the two never disagree. Returns '' when no
     * fundamental component is enabled/available.
     */
    private function fundamentalsReasonParagraph(StockBuySetupResult $r, ?float $priorYearRevenue): string
    {
        $bits = $this->reasonFundamentalBits($r, $priorYearRevenue);

        return $bits === [] ? '' : "Fundamentals:\n".implode('; ', array_map('ucfirst', $bits)).'.';
    }

    /**
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
