<?php

namespace App\Services\Stocks;

use Carbon\Carbon;

class NewsEventImpactService
{
    public function __construct(
        protected StockChartService $chartService,
        protected InstrumentClassifier $classifier,
    ) {}

    /**
     * Compute historical price changes around a news event timestamp across
     * 5m, 15m, 30m, 60m, and 4h horizons, plus optional related instruments.
     *
     * @param  array<int, string>|null  $compareSymbols
     * @return array<string, mixed>
     */
    public function analyzeImpact(string $symbol, int $eventTimestamp, ?array $compareSymbols = null): array
    {
        $instrument = $this->classifier->resolve($symbol);
        $upperSymbol = $instrument['symbol'];

        if ($compareSymbols === null) {
            $compareSymbols = array_values(array_map(
                fn (array $r) => (string) $r['symbol'],
                $instrument['related_symbols'] ?? []
            ));
        }

        $cleanCompare = [];
        foreach ($compareSymbols as $compSym) {
            $s = strtoupper(trim((string) $compSym));
            if ($s !== '' && $s !== $upperSymbol && preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $s)) {
                $cleanCompare[$s] = $s;
            }
        }
        $cleanCompare = array_slice(array_values($cleanCompare), 0, 4);

        $horizonsConfig = (array) config('market_data.news.impact_horizons', [
            ['key' => '5m', 'label' => '5 Minutes', 'minutes' => 5],
            ['key' => '15m', 'label' => '15 Minutes', 'minutes' => 15],
            ['key' => '30m', 'label' => '30 Minutes', 'minutes' => 30],
            ['key' => '60m', 'label' => '60 Minutes', 'minutes' => 60],
            ['key' => '4h', 'label' => '4 Hours', 'minutes' => 240],
        ]);

        $primaryImpact = $this->computeSymbolImpact($instrument, $eventTimestamp, $horizonsConfig);

        $comparisons = [];
        foreach ($cleanCompare as $compSymbol) {
            $compInstrument = $this->classifier->resolve($compSymbol);
            $comparisons[] = $this->computeSymbolImpact($compInstrument, $eventTimestamp, $horizonsConfig);
        }

        $eventCarbon = Carbon::createFromTimestamp($eventTimestamp, 'UTC');

        return [
            'symbol' => $upperSymbol,
            'name' => $instrument['name'] ?? $upperSymbol,
            'asset_type' => $instrument['asset_type'],
            'price_unit' => $instrument['price_unit'],
            'event_timestamp' => $eventTimestamp,
            'event_iso' => $eventCarbon->toIso8601String(),
            'event_display' => $eventCarbon->format('M j, Y H:i').' UTC',
            'primary' => $primaryImpact,
            'comparisons' => $comparisons,
            'available_comparisons' => $instrument['related_symbols'] ?? [],
            'disclaimer' => 'Observed historical price changes following publication timestamp. Coincidence in time does not imply causation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $instrument
     * @param  array<int, array{key: string, label: string, minutes: int}>  $horizonsConfig
     * @return array<string, mixed>
     */
    protected function computeSymbolImpact(array $instrument, int $eventTimestamp, array $horizonsConfig): array
    {
        $symbol = $instrument['symbol'];
        $eventUtc = Carbon::createFromTimestamp($eventTimestamp, 'UTC');
        $from = $eventUtc->copy()->subDays(2)->startOfDay();
        $to = $eventUtc->copy()->addDays(2)->endOfDay();

        $fiveMinCandles = [];
        $hourlyCandles = [];

        try {
            $fiveResp = $this->chartService->getCandles($symbol, '5min', $from, $to);
            $fiveMinCandles = $fiveResp['candles'] ?? [];
        } catch (\Throwable) {
            $fiveMinCandles = [];
        }

        try {
            $hourResp = $this->chartService->getCandles($symbol, '1hour', $from, $to);
            $hourlyCandles = $hourResp['candles'] ?? [];
        } catch (\Throwable) {
            $hourlyCandles = [];
        }

        $baselineBar = $this->findBaselineCandle($fiveMinCandles, $eventTimestamp, maxGapSeconds: 7200)
            ?? $this->findBaselineCandle($hourlyCandles, $eventTimestamp, maxGapSeconds: 10800);

        if ($baselineBar === null) {
            $unavailableHorizons = [];
            foreach ($horizonsConfig as $h) {
                $unavailableHorizons[] = [
                    'key' => $h['key'],
                    'label' => $h['label'],
                    'minutes' => (int) $h['minutes'],
                    'available' => false,
                    'baseline_price' => null,
                    'target_price' => null,
                    'change' => null,
                    'change_percent' => null,
                    'high' => null,
                    'low' => null,
                    'target_timestamp' => null,
                    'target_display' => null,
                    'reason' => 'Intraday data unavailable for this timestamp',
                ];
            }

            return [
                'symbol' => $symbol,
                'name' => $instrument['name'] ?? $symbol,
                'price_unit' => $instrument['price_unit'] ?? 'USD',
                'baseline_available' => false,
                'baseline_price' => null,
                'baseline_timestamp' => null,
                'baseline_display' => null,
                'horizons' => $unavailableHorizons,
            ];
        }

        $baselineTime = (int) $baselineBar['time'];
        // Use open if baseline candle starts at or after the event timestamp; otherwise use close
        $baselinePrice = ($baselineTime >= $eventTimestamp)
            ? (float) $baselineBar['open']
            : (float) $baselineBar['close'];

        $horizons = [];
        foreach ($horizonsConfig as $h) {
            $minutes = (int) $h['minutes'];
            $targetTimestamp = $baselineTime + ($minutes * 60);

            // Tolerance scales with horizon so session breaks aren't mismatched across days
            $toleranceSeconds = match (true) {
                $minutes <= 15 => 600,   // 10 min tolerance
                $minutes <= 30 => 900,   // 15 min tolerance
                $minutes <= 60 => 1800,  // 30 min tolerance
                default => 5400,         // 90 min tolerance for 4h
            };

            $targetBar = $this->findTargetCandle($fiveMinCandles, $baselineTime, $targetTimestamp, $toleranceSeconds);
            $seriesUsed = $fiveMinCandles;

            if ($targetBar === null && $minutes >= 60) {
                $targetBar = $this->findTargetCandle($hourlyCandles, $baselineTime, $targetTimestamp, $toleranceSeconds);
                $seriesUsed = $hourlyCandles;
            }

            if ($targetBar === null || $baselinePrice <= 0) {
                $horizons[] = [
                    'key' => $h['key'],
                    'label' => $h['label'],
                    'minutes' => $minutes,
                    'available' => false,
                    'baseline_price' => round($baselinePrice, 4),
                    'target_price' => null,
                    'change' => null,
                    'change_percent' => null,
                    'high' => null,
                    'low' => null,
                    'target_timestamp' => null,
                    'target_display' => null,
                    'reason' => 'Intraday bars unavailable for this horizon',
                ];

                continue;
            }

            $targetPrice = (float) $targetBar['close'];
            $change = round($targetPrice - $baselinePrice, 4);
            $changePct = round((($targetPrice - $baselinePrice) / $baselinePrice) * 100, 3);

            $high = max($baselinePrice, $targetPrice);
            $low = min($baselinePrice, $targetPrice);
            foreach ($seriesUsed as $bar) {
                if ($bar['time'] >= $baselineTime && $bar['time'] <= $targetBar['time']) {
                    $high = max($high, (float) $bar['high']);
                    $low = min($low, (float) $bar['low']);
                }
            }

            $horizons[] = [
                'key' => $h['key'],
                'label' => $h['label'],
                'minutes' => $minutes,
                'available' => true,
                'baseline_price' => round($baselinePrice, 4),
                'target_price' => round($targetPrice, 4),
                'change' => $change,
                'change_percent' => $changePct,
                'high' => round($high, 4),
                'low' => round($low, 4),
                'target_timestamp' => (int) $targetBar['time'],
                'target_display' => Carbon::createFromTimestamp((int) $targetBar['time'], 'UTC')->format('H:i').' UTC',
                'reason' => null,
            ];
        }

        return [
            'symbol' => $symbol,
            'name' => $instrument['name'] ?? $symbol,
            'price_unit' => $instrument['price_unit'] ?? 'USD',
            'baseline_available' => true,
            'baseline_price' => round($baselinePrice, 4),
            'baseline_timestamp' => $baselineTime,
            'baseline_display' => Carbon::createFromTimestamp($baselineTime, 'UTC')->format('M j, H:i').' UTC',
            'horizons' => $horizons,
        ];
    }

    /**
     * Find the candle at or immediately preceding/following the event timestamp.
     *
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return array{time: int, open: float, high: float, low: float, close: float, volume: float}|null
     */
    protected function findBaselineCandle(array $candles, int $eventTimestamp, int $maxGapSeconds = 7200): ?array
    {
        if (empty($candles)) {
            return null;
        }

        $lastAtOrBefore = null;
        $firstAfter = null;

        foreach ($candles as $bar) {
            $t = (int) $bar['time'];
            if ($t <= $eventTimestamp) {
                $lastAtOrBefore = $bar;
            } elseif ($firstAfter === null) {
                $firstAfter = $bar;
                break;
            }
        }

        if ($lastAtOrBefore !== null && ($eventTimestamp - (int) $lastAtOrBefore['time']) <= $maxGapSeconds) {
            return $lastAtOrBefore;
        }

        if ($firstAfter !== null && ((int) $firstAfter['time'] - $eventTimestamp) <= $maxGapSeconds) {
            return $firstAfter;
        }

        return null;
    }

    /**
     * Find the candle closest to the target horizon timestamp.
     *
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return array{time: int, open: float, high: float, low: float, close: float, volume: float}|null
     */
    protected function findTargetCandle(array $candles, int $baselineTime, int $targetTimestamp, int $toleranceSeconds): ?array
    {
        if (empty($candles)) {
            return null;
        }

        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($candles as $bar) {
            $t = (int) $bar['time'];
            if ($t <= $baselineTime) {
                continue;
            }

            $diff = abs($t - $targetTimestamp);
            if ($diff <= $toleranceSeconds && $diff < $bestDiff) {
                $best = $bar;
                $bestDiff = $diff;
            }
        }

        return $best;
    }
}
