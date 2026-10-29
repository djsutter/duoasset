<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketDataProviderException;
use App\Services\Stocks\StockChartService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockCandlesController extends Controller
{
    public const ALLOWED_INTERVALS = [
        '1m', '1min', '1minute',
        '5m', '5min', '5minute',
        '15m', '15min', '15minute',
        '30m', '30min', '30minute',
        '1h', '60m', '1hour', '60min',
        '1d', '1day', 'daily',
        '1w', 'w', 'week', '1week',
        '1mo', 'm', 'month', '1month',
    ];

    public const ALLOWED_RANGES = [
        '1M', '3M', '6M', '1Y', '5Y', 'ALL',
    ];

    public function __construct(
        protected StockChartService $chartService
    ) {}

    /**
     * Return normalized OHLCV candles for charting.
     */
    public function candles(Request $request, string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));

        // Validate symbol pattern allowing alphanumeric, periods, underscores, and dashes
        if (! preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $symbol)) {
            abort(404, 'Invalid stock symbol.');
        }

        // Validate interval if supplied
        $interval = '1day';
        if ($request->has('interval')) {
            $rawInterval = strtolower(trim((string) $request->query('interval')));
            if (! in_array($rawInterval, self::ALLOWED_INTERVALS, true)) {
                abort(422, 'Invalid interval parameter. Supported intervals: 1min, 5min, 15min, 30min, 1hour, 1day, 1week, 1month.');
            }
            $interval = $rawInterval;
        }

        // Validate range shorthand if supplied
        $range = null;
        if ($request->has('range')) {
            $rawRange = strtoupper(trim((string) $request->query('range')));
            if (! in_array($rawRange, self::ALLOWED_RANGES, true)) {
                abort(422, 'Invalid range parameter. Supported ranges: 1M, 3M, 6M, 1Y, 5Y, All.');
            }
            $range = $rawRange;
        }

        $from = null;
        if ($request->has('from')) {
            $fromRaw = trim((string) $request->query('from'));
            if ($fromRaw === '') {
                abort(422, 'Invalid from date parameter.');
            }
            try {
                $from = Carbon::parse($fromRaw, 'UTC');
            } catch (\Throwable) {
                abort(422, 'Invalid from date format. Please use YYYY-MM-DD.');
            }
        }

        $to = null;
        if ($request->has('to')) {
            $toRaw = trim((string) $request->query('to'));
            if ($toRaw === '') {
                abort(422, 'Invalid to date parameter.');
            }
            try {
                $to = Carbon::parse($toRaw, 'UTC');
            } catch (\Throwable) {
                abort(422, 'Invalid to date format. Please use YYYY-MM-DD.');
            }
        }

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            abort(422, 'The from date must be prior or equal to the to date.');
        }

        // Optional range shorthand (e.g. 1M, 3M, 6M, 1Y, 5Y, ALL)
        if ($from === null && $range !== null) {
            $refTo = $to ? $to->copy() : Carbon::now('UTC');
            $from = match ($range) {
                '1M' => $refTo->subDays(30),
                '3M' => $refTo->subDays(90),
                '6M' => $refTo->subDays(180),
                '1Y' => $refTo->subDays(365),
                '5Y' => $refTo->subYears(5),
                'ALL' => $refTo->subYears(20),
            };
        }

        try {
            $result = $this->chartService->getCandles($symbol, $interval, $from, $to);

            return response()->json($result);
        } catch (MarketDataProviderException $e) {
            $status = $e->getCode();
            $httpStatus = match (true) {
                $status === 429 => 429,
                ($status >= 500 && $status < 600) || in_array($status, [401, 402, 403], true) => 502,
                default => 503,
            };

            $message = match ($httpStatus) {
                429 => 'Market data provider rate limit exceeded. Please retry shortly.',
                default => 'Market data provider is currently unavailable. Please retry shortly.',
            };

            return response()->json([
                'message' => $message,
                'provider_status' => $status,
                'error' => $e->getMessage(),
            ], $httpStatus);
        }
    }
}
