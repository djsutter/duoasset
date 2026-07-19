<?php

use App\Services\MarketData\FmpMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

it('fetches and normalizes FMP intraday bars in ascending order', function () {
    Http::fake(function ($request) {
        expect($request->url())->toContain('/historical-chart/1hour');

        // FMP returns most-recent first.
        return Http::response([
            ['date' => '2026-07-17 15:00:00', 'open' => 10.0, 'high' => 10.5, 'low' => 9.9, 'close' => 10.4, 'volume' => 5000],
            ['date' => '2026-07-17 14:00:00', 'open' => 9.8, 'high' => 10.1, 'low' => 9.7, 'close' => 10.0, 'volume' => 4000],
        ], 200);
    });

    $fmp = new FmpMarketDataProvider('https://test.local/stable', 'test-key');

    $bars = $fmp->historicalIntradayBars(
        'XLK',
        '1hour',
        CarbonImmutable::parse('2026-07-16'),
        CarbonImmutable::parse('2026-07-17'),
    );

    expect($bars)->toHaveCount(2);
    // Ascending by datetime.
    expect($bars[0]['date'])->toBe('2026-07-17 14:00:00');
    expect($bars[1]['date'])->toBe('2026-07-17 15:00:00');
    expect($bars[1]['close'])->toBe(10.4);
    expect($bars[1]['volume'])->toBe(5000);
});

it('returns an empty array when the intraday endpoint fails', function () {
    Http::fake(fn () => Http::response('Premium Query Parameter', 402));

    $fmp = new FmpMarketDataProvider('https://test.local/stable', 'test-key');

    $bars = $fmp->historicalIntradayBars(
        'XLK',
        '1hour',
        CarbonImmutable::parse('2026-07-16'),
        CarbonImmutable::parse('2026-07-17'),
    );

    expect($bars)->toBe([]);
});
