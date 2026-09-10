<?php

namespace App\Services\Stocks;

use Carbon\CarbonImmutable;

/**
 * Calculates post-ignition price appreciation and historical peak gain.
 *
 * Excludes the ignition/spike day's own gain by using the spike bar's close
 * as the reference price. Movement beginning with the following trading day
 * counts as post-ignition appreciation.
 *
 * Peak gain is calculated from completed closing prices after ignition plus
 * the current/live quote (if available), ignoring historical intraday highs
 * to prevent wicks from permanently killing an otherwise valid signal.
 */
class IgnitionExtensionCalculator
{
    /**
     * Calculate reference price, current post-ignition gain %, and peak post-ignition gain %.
     *
     * @param  array<int, array<string, mixed>>  $bars
     * @param  CarbonImmutable|string  $spikeDate
     * @param  float|null  $currentPrice
     * @return array{
     *     reference_price: float|null,
     *     current_gain_pct: float|null,
     *     peak_gain_pct: float|null
     * }
     */
    public function calculate(
        array $bars,
        CarbonImmutable|string $spikeDate,
        ?float $currentPrice = null,
    ): array {
        $emptyResult = [
            'reference_price' => null,
            'current_gain_pct' => null,
            'peak_gain_pct' => null,
        ];

        if (empty($bars)) {
            return $emptyResult;
        }

        $targetDate = $spikeDate instanceof CarbonImmutable
            ? $spikeDate->toDateString()
            : substr((string) $spikeDate, 0, 10);

        if ($targetDate === '') {
            return $emptyResult;
        }

        // Sort bars by date ascending to ensure proper sequential ordering.
        $sortedBars = $bars;
        usort($sortedBars, function (array $a, array $b): int {
            $dateA = isset($a['date']) ? substr((string) $a['date'], 0, 10) : '';
            $dateB = isset($b['date']) ? substr((string) $b['date'], 0, 10) : '';

            return strcmp($dateA, $dateB);
        });

        $spikeIndex = null;
        foreach ($sortedBars as $idx => $bar) {
            $barDate = isset($bar['date']) ? substr((string) $bar['date'], 0, 10) : '';
            if ($barDate === $targetDate) {
                $spikeIndex = $idx;
                break;
            }
        }

        if ($spikeIndex === null) {
            return $emptyResult;
        }

        $spikeBar = $sortedBars[$spikeIndex];
        $referencePrice = isset($spikeBar['close']) && is_numeric($spikeBar['close'])
            ? (float) $spikeBar['close']
            : null;

        if ($referencePrice === null || $referencePrice <= 0.0) {
            return $emptyResult;
        }

        $postCloses = [];
        $latestBarClose = $referencePrice;
        $barCount = count($sortedBars);

        for ($i = $spikeIndex + 1; $i < $barCount; $i++) {
            $bar = $sortedBars[$i];
            if (isset($bar['close']) && is_numeric($bar['close'])) {
                $close = (float) $bar['close'];
                $postCloses[] = $close;
                $latestBarClose = $close;
            }
        }

        $effectiveCurrentPrice = $currentPrice !== null && $currentPrice > 0.0
            ? $currentPrice
            : $latestBarClose;

        if ($effectiveCurrentPrice <= 0.0) {
            return $emptyResult;
        }

        $candidates = array_merge([$referencePrice], $postCloses);
        if ($currentPrice !== null && $currentPrice > 0.0) {
            $candidates[] = $currentPrice;
        }

        $maximumPostPrice = max($candidates);

        $currentGainPct = (($effectiveCurrentPrice - $referencePrice) / $referencePrice) * 100.0;
        $peakGainPct = (($maximumPostPrice - $referencePrice) / $referencePrice) * 100.0;

        // Peak gain cannot be negative because maximumPostPrice is initialized to referencePrice.
        $peakGainPct = max(0.0, $peakGainPct);

        return [
            'reference_price' => $referencePrice,
            'current_gain_pct' => $currentGainPct,
            'peak_gain_pct' => $peakGainPct,
        ];
    }
}
