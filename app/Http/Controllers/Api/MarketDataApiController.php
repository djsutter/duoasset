<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MarketData\MarketDataProvider;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketDataApiController extends Controller
{
    public const ALLOWED_INTERVALS = [
        '1m', '1min', '5m', '5min', '15m', '15min', '30m', '30min', '1h', '1hour',
        '1d', '1day', 'daily', '1w', '1week', '1mo', '1month',
    ];

    public const ALLOWED_RANGES = [
        '1M', '3M', '6M', '1Y', '5Y', 'ALL',
    ];

    public function __construct(
        protected MarketDataProvider $provider
    ) {}

    public function candles(Request $request, string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));
        if (! preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $symbol)) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Invalid stock symbol format.',
            ], 404);
        }

        $interval = strtolower(trim((string) $request->query('interval', '1day')));
        if (! in_array($interval, self::ALLOWED_INTERVALS, true)) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Invalid interval parameter. Supported: 1min, 5min, 15min, 30min, 1hour, 1day, 1week, 1month.',
            ], 422);
        }

        $to = $request->has('to') ? CarbonImmutable::parse($request->query('to')) : CarbonImmutable::today();
        $from = null;

        if ($request->has('from')) {
            $from = CarbonImmutable::parse($request->query('from'));
        } elseif ($request->has('range')) {
            $range = strtoupper(trim((string) $request->query('range')));
            $from = match ($range) {
                '1M' => $to->subDays(30),
                '3M' => $to->subDays(90),
                '6M' => $to->subDays(180),
                '1Y' => $to->subDays(365),
                '5Y' => $to->subYears(5),
                'ALL' => $to->subYears(20),
                default => $to->subDays(365),
            };
        } else {
            $from = $to->subDays(365);
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $isIntraday = in_array($interval, ['1m', '1min', '5m', '5min', '15m', '15min', '30m', '30min', '1h', '1hour'], true);
        if ($isIntraday && abs($to->diffInDays($from)) > 60) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Intraday candle range cannot exceed 60 days to prevent excessive upstream consumption.',
            ], 422);
        }

        $isWeekly = in_array($interval, ['1w', '1week'], true);
        $isMonthly = in_array($interval, ['1mo', '1month'], true);

        if ($isIntraday) {
            $normInterval = match ($interval) {
                '1m', '1min' => '1min',
                '5m', '5min' => '5min',
                '15m', '15min' => '15min',
                '30m', '30min' => '30min',
                default => '1hour',
            };
            $candles = $this->provider->historicalIntradayBars($symbol, $normInterval, $from, $to);
        } elseif ($isWeekly) {
            $daily = $this->provider->historicalDailyBars($symbol, $from, $to);
            $candles = $this->aggregateToWeekly($daily);
        } elseif ($isMonthly) {
            $daily = $this->provider->historicalDailyBars($symbol, $from, $to);
            $candles = $this->aggregateToMonthly($daily);
        } else {
            $candles = $this->provider->historicalDailyBars($symbol, $from, $to);
        }

        return response()->json([
            'provider' => 'duoasset',
            'symbol' => $symbol,
            'interval' => $interval,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => count($candles),
            'candles' => $candles,
        ]);
    }

    /**
     * Aggregate daily OHLCV bars into weekly bars (starting Monday).
     */
    protected function aggregateToWeekly(array $dailyBars): array
    {
        $weeks = [];

        foreach ($dailyBars as $bar) {
            $dt = CarbonImmutable::parse($bar['date']);
            $weekStart = $dt->startOfWeek()->toDateString();

            if (! isset($weeks[$weekStart])) {
                $weeks[$weekStart] = [
                    'date' => $weekStart,
                    'open' => (float) $bar['open'],
                    'high' => (float) $bar['high'],
                    'low' => (float) $bar['low'],
                    'close' => (float) $bar['close'],
                    'adj_close' => isset($bar['adj_close']) && $bar['adj_close'] !== null ? (float) $bar['adj_close'] : null,
                    'volume' => (int) $bar['volume'],
                ];
            } else {
                $weeks[$weekStart]['high'] = max((float) $weeks[$weekStart]['high'], (float) $bar['high']);
                $weeks[$weekStart]['low'] = min((float) $weeks[$weekStart]['low'], (float) $bar['low']);
                $weeks[$weekStart]['close'] = (float) $bar['close'];
                if (isset($bar['adj_close']) && $bar['adj_close'] !== null) {
                    $weeks[$weekStart]['adj_close'] = (float) $bar['adj_close'];
                }
                $weeks[$weekStart]['volume'] += (int) $bar['volume'];
            }
        }

        return array_values($weeks);
    }

    /**
     * Aggregate daily OHLCV bars into monthly bars (starting 1st of month).
     */
    protected function aggregateToMonthly(array $dailyBars): array
    {
        $months = [];

        foreach ($dailyBars as $bar) {
            $dt = CarbonImmutable::parse($bar['date']);
            $monthStart = $dt->startOfMonth()->toDateString();

            if (! isset($months[$monthStart])) {
                $months[$monthStart] = [
                    'date' => $monthStart,
                    'open' => (float) $bar['open'],
                    'high' => (float) $bar['high'],
                    'low' => (float) $bar['low'],
                    'close' => (float) $bar['close'],
                    'adj_close' => isset($bar['adj_close']) && $bar['adj_close'] !== null ? (float) $bar['adj_close'] : null,
                    'volume' => (int) $bar['volume'],
                ];
            } else {
                $months[$monthStart]['high'] = max((float) $months[$monthStart]['high'], (float) $bar['high']);
                $months[$monthStart]['low'] = min((float) $months[$monthStart]['low'], (float) $bar['low']);
                $months[$monthStart]['close'] = (float) $bar['close'];
                if (isset($bar['adj_close']) && $bar['adj_close'] !== null) {
                    $months[$monthStart]['adj_close'] = (float) $bar['adj_close'];
                }
                $months[$monthStart]['volume'] += (int) $bar['volume'];
            }
        }

        return array_values($months);
    }

    public function profile(Request $request, string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));
        if (! preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $symbol)) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Invalid stock symbol format.',
            ], 404);
        }

        $profile = $this->provider->profile($symbol);
        if (! $profile) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => "Company profile for symbol [{$symbol}] not found.",
            ], 404);
        }

        return response()->json([
            'provider' => 'duoasset',
            'symbol' => $symbol,
            'profile' => $profile,
        ]);
    }

    public function quote(Request $request, string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));
        if (! preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $symbol)) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Invalid stock symbol format.',
            ], 404);
        }

        $quote = $this->provider->quote($symbol);
        if (! $quote) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => "Quote for symbol [{$symbol}] not found.",
            ], 404);
        }

        return response()->json([
            'provider' => 'duoasset',
            'symbol' => $symbol,
            'quote' => $quote,
        ]);
    }

    public function financials(Request $request, string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));
        if (! preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $symbol)) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Invalid stock symbol format.',
            ], 404);
        }

        $statement = strtolower(trim((string) $request->query('statement', 'income')));
        $limit = max(1, min(40, (int) $request->query('limit', 8)));

        $rows = match ($statement) {
            'balance_sheet', 'balance' => $this->provider->quarterlyBalanceSheets($symbol, $limit),
            'cash_flow', 'cash' => $this->provider->quarterlyCashFlowStatements($symbol, $limit),
            default => $this->provider->quarterlyIncomeStatements($symbol, $limit),
        };

        return response()->json([
            'provider' => 'duoasset',
            'symbol' => $symbol,
            'statement' => $statement,
            'count' => count($rows),
            'financials' => $rows,
        ]);
    }

    public function earningsCalendar(Request $request): JsonResponse
    {
        $from = $request->has('from')
            ? CarbonImmutable::parse($request->query('from'))
            : CarbonImmutable::today()->startOfWeek();

        $to = $request->has('to')
            ? CarbonImmutable::parse($request->query('to'))
            : CarbonImmutable::today()->endOfWeek();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $events = $this->provider->earningsCalendar($from, $to);

        return response()->json([
            'provider' => 'duoasset',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => count($events),
            'events' => $events,
        ]);
    }

    public function earningsSurprises(Request $request): JsonResponse
    {
        $from = $request->has('from')
            ? CarbonImmutable::parse($request->query('from'))
            : CarbonImmutable::today()->subMonths(3);

        $to = $request->has('to')
            ? CarbonImmutable::parse($request->query('to'))
            : CarbonImmutable::today();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $surprises = $this->provider->earningsSurprises($from, $to);

        return response()->json([
            'provider' => 'duoasset',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => count($surprises),
            'surprises' => $surprises,
        ]);
    }
}
