<?php

namespace App\Services\MarketData;

/**
 * Canonical, provider-agnostic market-cap computation.
 *
 * Market cap is defined as: current share price × shares outstanding.
 *
 * This helper is used everywhere the codebase used to read or write a
 * raw `market_cap` value, so the formula lives in exactly one place.
 * When either input is missing, callers may pass a `$fallback` value
 * (typically the provider-reported market cap) to keep behaviour
 * backward-compatible while the system migrates to capturing shares
 * outstanding everywhere.
 */
final class MarketCap
{
    /**
     * Compute market cap as price × shares_outstanding.
     *
     * @param  float|int|string|null  $price  Current share price (any numeric form).
     * @param  float|int|string|null  $shares  Shares outstanding.
     * @param  float|int|string|null  $fallback  Provider-reported market cap, used
     *                                           when price/shares are not both available.
     * @return int|null Whole-unit market cap, or null when nothing can be computed.
     */
    public static function compute(
        float|int|string|null $price,
        float|int|string|null $shares,
        float|int|string|null $fallback = null,
    ): ?int {
        $p = is_numeric($price) ? (float) $price : null;
        $s = is_numeric($shares) ? (float) $shares : null;

        if ($p !== null && $s !== null && $p > 0 && $s > 0) {
            return (int) round($p * $s);
        }

        if ($fallback === null || $fallback === '') {
            return null;
        }

        return is_numeric($fallback) ? (int) $fallback : null;
    }
}
