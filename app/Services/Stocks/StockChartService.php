<?php

namespace App\Services\Stocks;

use App\Services\MarketData\MarketDataProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class StockChartService
{
    public function __construct(
        protected MarketDataProvider $provider
    ) {}

    /**
     * Retrieve normalized and validated candle data for the charting engine.
     *
     * @return array{
     *     symbol: string,
     *     interval: string,
     *     candles: array<int, array{
     *         time: int,
     *         open: float,
     *         high: float,
     *         low: float,
     *         close: float,
     *         volume: float
     *     }>
     * }
     */
    public function getCandles(
        string $symbol,
        string $interval = '1day',
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null
    ): array {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [
                'symbol' => '',
                'interval' => $interval,
                'candles' => [],
            ];
        }

        $canonicalInterval = $this->normalizeInterval($interval);

        $to = $to ? Carbon::instance($to)->copy()->utc() : Carbon::now('UTC');
        if ($from === null) {
            $from = match ($canonicalInterval) {
                '1min' => $to->copy()->subDays(5),
                '5min' => $to->copy()->subDays(15),
                '15min', '30min' => $to->copy()->subDays(30),
                '1hour' => $to->copy()->subDays(60),
                '1week' => $to->copy()->subYears(5),
                '1month' => $to->copy()->subYears(10),
                default => $to->copy()->subDays(365), // 1day default ~1 year
            };
        } else {
            $from = Carbon::instance($from)->copy()->utc();
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        // Apply sensible upper bounds for intraday intervals to prevent excessive payloads and provider timeouts
        $maxIntradayDays = match ($canonicalInterval) {
            '1min' => 30,
            '5min' => 60,
            '15min', '30min' => 90,
            '1hour' => 180,
            default => null,
        };

        if ($maxIntradayDays !== null && $to->diffInDays($from) > $maxIntradayDays) {
            $from = $to->copy()->subDays($maxIntradayDays);
        }

        // Cache daily/intraday candle responses
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $cacheTtl = in_array($canonicalInterval, ['1day', '1week', '1month'], true) ? 3600 : 300;
        $cacheKey = "stock_chart.candles.{$symbol}.{$canonicalInterval}.{$fromStr}.{$toStr}";

        $candles = Cache::remember($cacheKey, $cacheTtl, function () use ($symbol, $canonicalInterval, $from, $to) {
            return $this->fetchAndProcessCandles($symbol, $canonicalInterval, $from, $to);
        });

        return [
            'symbol' => $symbol,
            'interval' => $canonicalInterval,
            'from' => $fromStr,
            'to' => $toStr,
            'count' => count($candles),
            'candles' => $candles,
        ];
    }

    /**
     * Fetch raw bars from MarketDataProvider and normalize to validated OHLCV candles.
     *
     * @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    protected function fetchAndProcessCandles(
        string $symbol,
        string $interval,
        CarbonInterface $from,
        CarbonInterface $to
    ): array {
        if ($interval === '1week' || $interval === '1month') {
            $dailyBars = $this->provider->historicalDailyBars($symbol, $from, $to);
            $normalizedDaily = $this->normalizeRawBars($dailyBars);
            return $interval === '1week'
                ? $this->aggregateToWeekly($normalizedDaily)
                : $this->aggregateToMonthly($normalizedDaily);
        }

        if (in_array($interval, ['1min', '5min', '15min', '30min', '1hour'], true)) {
            $rawBars = $this->provider->historicalIntradayBars($symbol, $interval, $from, $to);
            return $this->normalizeRawBars($rawBars, isIntraday: true);
        }

        // Default '1day'
        $dailyBars = $this->provider->historicalDailyBars($symbol, $from, $to);
        return $this->normalizeRawBars($dailyBars, isIntraday: false);
    }

    /**
     * Normalize, validate, deduplicate, and sort raw bars.
     *
     * @param array<int, array<string, mixed>> $rawBars
     * @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    protected function normalizeRawBars(array $rawBars, bool $isIntraday = false): array
    {
        $nowTimestamp = Carbon::now('UTC')->endOfDay()->timestamp + 86400; // max reasonable cutoff
        $byTimestamp = [];

        foreach ($rawBars as $bar) {
            if (! is_array($bar)) {
                continue;
            }

            $dateRaw = $bar['date'] ?? null;
            if (! $dateRaw) {
                continue;
            }

            try {
                $c = Carbon::parse($dateRaw, 'UTC');
                $timestamp = $c->timestamp;
            } catch (\Throwable) {
                continue;
            }

            // Prevent future data
            if ($timestamp > $nowTimestamp) {
                continue;
            }

            $open = isset($bar['open']) && is_numeric($bar['open']) ? (float) $bar['open'] : null;
            $high = isset($bar['high']) && is_numeric($bar['high']) ? (float) $bar['high'] : null;
            $low = isset($bar['low']) && is_numeric($bar['low']) ? (float) $bar['low'] : null;
            $close = isset($bar['close']) && is_numeric($bar['close']) ? (float) $bar['close'] : null;
            $volume = isset($bar['volume']) && is_numeric($bar['volume']) ? max(0.0, (float) $bar['volume']) : 0.0;

            if ($open === null || $close === null || $open <= 0 || $close <= 0) {
                continue;
            }

            $high = $high !== null && $high > 0 ? max($high, $open, $close) : max($open, $close);
            $low = $low !== null && $low > 0 ? min($low, $open, $close) : min($open, $close);

            $byTimestamp[$timestamp] = [
                'time' => (int) $timestamp,
                'open' => round($open, 4),
                'high' => round($high, 4),
                'low' => round($low, 4),
                'close' => round($close, 4),
                'volume' => round($volume, 2),
            ];
        }

        // Sort chronologically ascending
        ksort($byTimestamp, SORT_NUMERIC);

        return array_values($byTimestamp);
    }

    /**
     * Aggregate daily OHLCV bars into weekly bars (starting Monday).
     *
     * @param array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}> $dailyBars
     * @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    protected function aggregateToWeekly(array $dailyBars): array
    {
        $weeks = [];

        foreach ($dailyBars as $bar) {
            $dt = Carbon::createFromTimestamp($bar['time'], 'UTC');
            $weekKey = $dt->isoWeekYear().'-W'.str_pad((string) $dt->isoWeek(), 2, '0', STR_PAD_LEFT);

            if (! isset($weeks[$weekKey])) {
                $weekStart = $dt->copy()->startOfWeek();
                $weeks[$weekKey] = [
                    'time' => $weekStart->timestamp,
                    'open' => $bar['open'],
                    'high' => $bar['high'],
                    'low' => $bar['low'],
                    'close' => $bar['close'],
                    'volume' => $bar['volume'],
                ];
            } else {
                $weeks[$weekKey]['high'] = max($weeks[$weekKey]['high'], $bar['high']);
                $weeks[$weekKey]['low'] = min($weeks[$weekKey]['low'], $bar['low']);
                $weeks[$weekKey]['close'] = $bar['close'];
                $weeks[$weekKey]['volume'] += $bar['volume'];
            }
        }

        return array_values($weeks);
    }

    /**
     * Aggregate daily OHLCV bars into monthly bars (starting 1st of month).
     *
     * @param array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}> $dailyBars
     * @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    protected function aggregateToMonthly(array $dailyBars): array
    {
        $months = [];

        foreach ($dailyBars as $bar) {
            $dt = Carbon::createFromTimestamp($bar['time'], 'UTC');
            $monthKey = $dt->format('Y-m');

            if (! isset($months[$monthKey])) {
                $monthStart = $dt->copy()->startOfMonth();
                $months[$monthKey] = [
                    'time' => $monthStart->timestamp,
                    'open' => $bar['open'],
                    'high' => $bar['high'],
                    'low' => $bar['low'],
                    'close' => $bar['close'],
                    'volume' => $bar['volume'],
                ];
            } else {
                $months[$monthKey]['high'] = max($months[$monthKey]['high'], $bar['high']);
                $months[$monthKey]['low'] = min($months[$monthKey]['low'], $bar['low']);
                $months[$monthKey]['close'] = $bar['close'];
                $months[$monthKey]['volume'] += $bar['volume'];
            }
        }

        return array_values($months);
    }

    /**
     * Normalize interval strings.
     */
    public function normalizeInterval(string $interval): string
    {
        $i = strtolower(trim($interval));

        return match ($i) {
            '1m', '1min', '1minute' => '1min',
            '5m', '5min', '5minute' => '5min',
            '15m', '15min', '15minute' => '15min',
            '30m', '30min', '30minute' => '30min',
            '1h', '60m', '1hour', '60min' => '1hour',
            '1w', 'w', 'week', '1week' => '1week',
            '1mo', 'm', 'month', '1month' => '1month',
            default => '1day',
        };
    }
}
