<?php

namespace App\Services\Earnings;

/**
 * Reject untrustworthy raw EPS values before creating an earnings event/alert.
 * This is a defensive data-quality check, not an adjustment for stock splits.
 */
final class EpsSurpriseSanity
{
    /**
     * Conservative per-share EPS ceiling when a matching share price is known.
     * A legitimate high-priced stock may have EPS > $1,000; allow that when
     * supported by its share price rather than imposing a global fixed ceiling.
     */
    private const MIN_PRICE_AWARE_EPS_CEILING = 100.0;

    private const MAX_EPS_TO_PRICE_MULTIPLE = 100.0;

    /** Ceiling used when no reliable share price accompanies the EPS record. */
    private const NO_PRICE_EPS_CEILING = 1000.0;

    /**
     * Calculate a validated earnings surprise in percentage points.
     *
     * @param mixed $actual      Provider's epsActual
     * @param mixed $estimated   Provider's epsEstimated
     * @param mixed $sharePrice  Optional current/historical share price in the SAME currency
     * @return float|null       Null means no trustworthy surprise should be calculated.
     *                          Estimates under two cents are also rejected to
     *                          avoid near-zero denominators (existing rule).
     */
    public static function calculatePercent(mixed $actual, mixed $estimated, mixed $sharePrice = null): ?float
    {
        if (! is_numeric($actual) || ! is_numeric($estimated)) {
            return null;
        }

        $actual = (float) $actual;
        $estimated = (float) $estimated;
        if (! is_finite($actual) || ! is_finite($estimated) || abs($estimated) < 0.02) {
            return null;
        }

        $epsCeiling = self::NO_PRICE_EPS_CEILING;
        if (is_numeric($sharePrice)) {
            $price = (float) $sharePrice;
            if (is_finite($price) && $price > 0.0) {
                $epsCeiling = max(
                    self::MIN_PRICE_AWARE_EPS_CEILING,
                    self::MAX_EPS_TO_PRICE_MULTIPLE * $price
                );
            }
        }

        // The GPUS example: estimate -28,540.2169 vs actual -0.30 at a
        // sub-dollar stock price. Treat it as an unverified provider input,
        // not a 100% EPS beat. Do NOT clamp the value: reject the event.
        if (abs($estimated) > $epsCeiling || abs($actual) > $epsCeiling) {
            return null;
        }

        $result = (($actual - $estimated) / abs($estimated)) * 100.0;

        return is_finite($result) ? $result : null;
    }

    /**
     * Flag mathematically valid but extreme percentages for independent
     * verification before publishing an automated earnings-beat alert.
     *
     * A review flag DOES NOT prove the estimate is incorrect: a tiny
     * consensus EPS can yield a legitimate four- or five-digit percentage.
     * Verify against another consensus source, period, diluted/GAAP basis,
     * and any stock-split adjustments. Keep the raw data for auditing.
     */
    public static function requiresReview(mixed $actual, mixed $estimated, mixed $sharePrice = null): bool
    {
        $percent = self::calculatePercent($actual, $estimated, $sharePrice);

        if ($percent === null) {
            return true;
        }

        // Includes ANAB (+25,450%), PURR (+14,100%), BBGI (+3,581%),
        // FISH.V (+3,426%) from the user's watchlist example.
        if (abs($percent) >= 3000.0) {
            return true;
        }

        // Very small estimates amplify modest absolute EPS differences.
        return abs((float) $estimated) < 0.05 && abs($percent) >= 500.0;
    }
}
