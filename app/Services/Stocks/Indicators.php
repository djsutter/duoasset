<?php

namespace App\Services\Stocks;

/**
 * Tiny pure-PHP indicator helpers used by the buy-setup scanner.
 *
 * All inputs are simple arrays of floats/ints; no DB or service deps.
 * Methods are intentionally tolerant of short series — they return null
 * (or 0.0 / [] depending on the contract) when there is not enough data.
 */
class Indicators
{
    /**
     * Simple Moving Average over the last $period closes.
     */
    public static function sma(array $closes, int $period): ?float
    {
        $n = count($closes);
        if ($period <= 0 || $n < $period) {
            return null;
        }

        $slice = array_slice($closes, $n - $period, $period);
        $sum = 0.0;
        foreach ($slice as $v) {
            $sum += (float) $v;
        }

        return $sum / $period;
    }

    /**
     * Average True Range over the most recent $period bars.
     *
     * Expects bars as ['high'=>float,'low'=>float,'close'=>float,...] in
     * ascending date order.
     */
    public static function atr(array $bars, int $period = 14): ?float
    {
        $n = count($bars);
        if ($period <= 0 || $n < $period + 1) {
            return null;
        }

        $trs = [];
        for ($i = $n - $period; $i < $n; $i++) {
            $high = (float) ($bars[$i]['high'] ?? 0);
            $low = (float) ($bars[$i]['low'] ?? 0);
            $prevClose = (float) ($bars[$i - 1]['close'] ?? 0);

            $trs[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        return array_sum($trs) / $period;
    }

    /**
     * Linear-regression slope (least-squares) of $values vs. their index.
     * Returns 0.0 for trivially-short input.
     */
    public static function slope(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $x = (float) $i;
            $y = (float) $values[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if ($denom == 0.0) {
            return 0.0;
        }

        return (($n * $sumXY) - ($sumX * $sumY)) / $denom;
    }

    /**
     * Wilder's Relative Strength Index over the last $period+1 closes.
     * Returns null when there is not enough data (needs $period+1 closes
     * to derive $period price changes).
     */
    public static function rsi(array $closes, int $period = 14): ?float
    {
        $n = count($closes);
        if ($period <= 0 || $n < $period + 1) {
            return null;
        }

        $slice = array_slice($closes, $n - $period - 1, $period + 1);
        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i < count($slice); $i++) {
            $change = (float) $slice[$i] - (float) $slice[$i - 1];
            if ($change >= 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        if ($avgLoss == 0.0) {
            return $avgGain == 0.0 ? 50.0 : 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100.0 - (100.0 / (1.0 + $rs));
    }

    /**
     * Maximum volume in the last $days bars (excluding the final bar by
     * default — useful for "prior" comparison).
     *
     * Pass $excludeLast=false to include the most recent bar.
     */
    public static function maxVolumeIn(array $bars, int $days, bool $excludeLast = false): int
    {
        $n = count($bars);
        if ($n === 0 || $days <= 0) {
            return 0;
        }

        $end = $excludeLast ? $n - 1 : $n;
        $start = max(0, $end - $days);
        $max = 0;
        for ($i = $start; $i < $end; $i++) {
            $v = (int) ($bars[$i]['volume'] ?? 0);
            if ($v > $max) {
                $max = $v;
            }
        }

        return $max;
    }
}
