<?php

namespace App\Http\Controllers;

use App\Services\Stocks\StockChartService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockCandlesController extends Controller
{
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

        $interval = (string) $request->query('interval', '1day');

        $from = null;
        if ($request->has('from')) {
            try {
                $from = Carbon::parse((string) $request->query('from'), 'UTC');
            } catch (\Throwable) {
                $from = null;
            }
        }

        $to = null;
        if ($request->has('to')) {
            try {
                $to = Carbon::parse((string) $request->query('to'), 'UTC');
            } catch (\Throwable) {
                $to = null;
            }
        }

        // Optional range shorthand (e.g. 1M, 3M, 6M, 1Y, 5Y, ALL)
        if ($from === null && $request->has('range')) {
            $range = strtoupper(trim((string) $request->query('range')));
            $refTo = $to ? $to->copy() : Carbon::now('UTC');
            $from = match ($range) {
                '1M' => $refTo->subDays(30),
                '3M' => $refTo->subDays(90),
                '6M' => $refTo->subDays(180),
                '1Y' => $refTo->subDays(365),
                '5Y' => $refTo->subYears(5),
                'ALL' => $refTo->subYears(20),
                default => null,
            };
        }

        $result = $this->chartService->getCandles($symbol, $interval, $from, $to);

        return response()->json($result);
    }
}
