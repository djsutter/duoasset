<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * Shared Http::fake helpers for the Sector Money Flows tests. Required from
 * tests/Pest.php so both the service and command feature tests can use them.
 */

if (! function_exists('fakeMoneyFlowFmp')) {
    /**
     * Fake FMP daily + intraday endpoints. Symbols in $emptyFor return no bars
     * (to simulate missing/gated data); every other symbol gets a generated
     * ascending series so its sector can be scored.
     *
     * @param  array<int, string>  $emptyFor
     */
    function fakeMoneyFlowFmp(array $emptyFor = [], float $priceStep = 0.5): void
    {
        Http::fake(function ($request) use ($emptyFor, $priceStep) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $symbol = strtoupper((string) ($query['symbol'] ?? ''));

            if (in_array($symbol, $emptyFor, true)) {
                return Http::response([], 200);
            }

            if (str_contains($url, 'historical-price-eod/full')) {
                return Http::response(moneyFlowDailySeries($priceStep), 200);
            }

            if (str_contains($url, 'historical-chart')) {
                return Http::response(moneyFlowIntradaySeries($priceStep), 200);
            }

            return Http::response([], 200);
        });
    }
}

if (! function_exists('moneyFlowDailySeries')) {
    /** @return array<int, array<string, mixed>> */
    function moneyFlowDailySeries(float $step = 0.5, int $n = 60): array
    {
        $rows = [];
        $base = CarbonImmutable::parse('2026-07-17');
        for ($i = 0; $i < $n; $i++) {
            $close = 100.0 + ($i * $step);
            $rows[] = [
                'date' => $base->subDays($n - 1 - $i)->toDateString(),
                'open' => $close,
                'high' => $close + 0.5,
                'low' => $close - 0.5,
                'close' => $close,
                'adjClose' => $close,
                'volume' => 1_000_000 + ($i * 1000),
            ];
        }

        return $rows;
    }
}

if (! function_exists('moneyFlowIntradaySeries')) {
    /** @return array<int, array<string, mixed>> */
    function moneyFlowIntradaySeries(float $step = 0.5, int $n = 30): array
    {
        $rows = [];
        $base = CarbonImmutable::parse('2026-07-17 15:00:00');
        for ($i = 0; $i < $n; $i++) {
            $close = 100.0 + ($i * $step);
            $rows[] = [
                'date' => $base->subHours($n - 1 - $i)->format('Y-m-d H:i:s'),
                'open' => $close,
                'high' => $close + 0.2,
                'low' => $close - 0.2,
                'close' => $close,
                'volume' => 200_000 + ($i * 500),
            ];
        }

        return $rows;
    }
}
