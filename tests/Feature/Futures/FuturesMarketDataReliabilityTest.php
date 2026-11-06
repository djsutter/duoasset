<?php

use App\Models\MarketDataPayload;
use App\Models\User;
use App\Services\MarketData\FmpMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MarketData\PersistentMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Build a realistic Yahoo Finance v8 chart JSON payload for continuous futures contracts.
 *
 * @param  list<array{ts:int,open:float,high:float,low:float,close:float,volume:int}>  $bars
 * @return array<string, mixed>
 */
function makeYahooChartPayload(string $yahooSymbol, float $regularPrice, float $prevClose, int $marketTime, array $bars): array
{
    return [
        'chart' => [
            'result' => [
                [
                    'meta' => [
                        'currency' => 'USD',
                        'symbol' => $yahooSymbol,
                        'exchangeName' => 'NYM',
                        'instrumentType' => 'FUTURE',
                        'regularMarketPrice' => $regularPrice,
                        'chartPreviousClose' => $prevClose,
                        'previousClose' => $prevClose,
                        'regularMarketDayHigh' => max(array_column($bars, 'high') ?: [$regularPrice]),
                        'regularMarketDayLow' => min(array_column($bars, 'low') ?: [$regularPrice]),
                        'regularMarketVolume' => array_sum(array_column($bars, 'volume')),
                        'regularMarketTime' => $marketTime,
                    ],
                    'timestamp' => array_column($bars, 'ts'),
                    'indicators' => [
                        'quote' => [
                            [
                                'open' => array_column($bars, 'open'),
                                'high' => array_column($bars, 'high'),
                                'low' => array_column($bars, 'low'),
                                'close' => array_column($bars, 'close'),
                                'volume' => array_column($bars, 'volume'),
                            ],
                        ],
                        'adjclose' => [
                            [
                                'adjclose' => array_column($bars, 'close'),
                            ],
                        ],
                    ],
                ],
            ],
            'error' => null,
        ],
    ];
}

test('all 5 required futures instruments (GCUSD, SIUSD, CLUSD, NGUSD, HGUSD) resolve quotes, daily candles, and intraday candles including 4h when FMP restricts CLUSD, NGUSD, and HGUSD with HTTP 402', function () {
    Cache::flush();

    $d1Ts = CarbonImmutable::parse('2026-11-03 12:00:00', 'America/New_York')->utc()->timestamp;
    $d2Ts = CarbonImmutable::parse('2026-11-04 12:00:00', 'America/New_York')->utc()->timestamp;
    $h1Ts = CarbonImmutable::parse('2026-11-04 08:00:00', 'America/New_York')->utc()->timestamp;
    $h2Ts = CarbonImmutable::parse('2026-11-04 09:00:00', 'America/New_York')->utc()->timestamp;
    $h3Ts = CarbonImmutable::parse('2026-11-04 12:00:00', 'America/New_York')->utc()->timestamp;

    Http::fake(function ($request) use ($d1Ts, $d2Ts, $h1Ts, $h2Ts, $h3Ts) {
        $url = (string) $request->url();

        // FMP responses: GCUSD and SIUSD succeed; CLUSD, NGUSD, HGUSD return HTTP 402 (matching production)
        if (str_contains($url, 'financialmodelingprep.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $sym = strtoupper((string) ($q['symbol'] ?? ''));

            if (in_array($sym, ['CLUSD', 'NGUSD', 'HGUSD'], true)) {
                return Http::response(
                    "Premium Query Parameter: 'Special Endpoint : This value set for 'symbol' is not available under your current subscription'",
                    402
                );
            }

            $basePrice = $sym === 'GCUSD' ? 4070.50 : 51.96;

            if (str_contains($url, '/quote')) {
                return Http::response([
                    [
                        'symbol' => $sym,
                        'name' => $sym === 'GCUSD' ? 'Gold Futures' : 'Silver Futures',
                        'price' => $basePrice,
                        'change' => 12.40,
                        'changePercentage' => 0.31,
                        'volume' => 180000,
                        'exchange' => 'COMMODITY',
                        'open' => $basePrice - 10,
                        'previousClose' => $basePrice - 12.40,
                        'dayLow' => $basePrice - 15,
                        'dayHigh' => $basePrice + 8,
                        'timestamp' => $d2Ts,
                    ],
                ], 200);
            }

            if (str_contains($url, '/historical-price-eod/full')) {
                return Http::response([
                    ['date' => '2026-11-04', 'open' => $basePrice - 5, 'high' => $basePrice + 5, 'low' => $basePrice - 8, 'close' => $basePrice, 'volume' => 180000],
                    ['date' => '2026-11-03', 'open' => $basePrice - 15, 'high' => $basePrice - 2, 'low' => $basePrice - 20, 'close' => $basePrice - 5, 'volume' => 165000],
                ], 200);
            }

            if (str_contains($url, '/historical-chart/')) {
                return Http::response([
                    ['date' => '2026-11-04 12:00:00', 'open' => $basePrice - 1, 'high' => $basePrice + 2, 'low' => $basePrice - 2, 'close' => $basePrice, 'volume' => 12000],
                    ['date' => '2026-11-04 08:00:00', 'open' => $basePrice - 4, 'high' => $basePrice, 'low' => $basePrice - 5, 'close' => $basePrice - 1, 'volume' => 9500],
                ], 200);
            }

            return Http::response([], 200);
        }

        // Yahoo Finance continuous front-month fallback for CL=F, NG=F, HG=F
        if (str_contains($url, 'query1.finance.yahoo.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $interval = (string) ($q['interval'] ?? '1d');

            [$yahooSym, $price, $prev] = match (true) {
                str_contains($url, 'CL%3DF') || str_contains($url, 'CL=F') => ['CL=F', 71.45, 70.10],
                str_contains($url, 'NG%3DF') || str_contains($url, 'NG=F') => ['NG=F', 2.96, 2.88],
                default => ['HG=F', 4.52, 4.46],
            };

            if ($interval === '1d') {
                return Http::response(makeYahooChartPayload($yahooSym, $price, $prev, $d2Ts, [
                    ['ts' => $d1Ts, 'open' => $prev - 0.2, 'high' => $prev + 0.5, 'low' => $prev - 0.4, 'close' => $prev, 'volume' => 95000],
                    ['ts' => $d2Ts, 'open' => $prev, 'high' => $price + 0.6, 'low' => $prev - 0.1, 'close' => $price, 'volume' => 112000],
                ]), 200);
            }

            return Http::response(makeYahooChartPayload($yahooSym, $price, $prev, $h3Ts, [
                ['ts' => $h1Ts, 'open' => $prev, 'high' => $prev + 0.3, 'low' => $prev - 0.1, 'close' => $prev + 0.2, 'volume' => 14000],
                ['ts' => $h2Ts, 'open' => $prev + 0.2, 'high' => $price, 'low' => $prev + 0.1, 'close' => $price - 0.1, 'volume' => 16000],
                ['ts' => $h3Ts, 'open' => $price - 0.1, 'high' => $price + 0.2, 'low' => $price - 0.2, 'close' => $price, 'volume' => 18000],
            ]), 200);
        }

        return Http::response([], 200);
    });

    $fmp = new FmpMarketDataProvider('https://financialmodelingprep.com/stable', 'live-key');
    $persistent = new PersistentMarketDataProvider($fmp);
    app()->instance(MarketDataProvider::class, $persistent);

    $user = User::factory()->futures()->create(['email_verified_at' => now()]);
    $this->actingAs($user);

    $expectedUnits = [
        'GCUSD' => 'USD / troy oz',
        'SIUSD' => 'USD / troy oz',
        'CLUSD' => 'USD / bbl',
        'NGUSD' => 'USD / MMBtu',
        'HGUSD' => 'USD / lb',
    ];

    foreach ($expectedUnits as $symbol => $unit) {
        $slug = strtolower($symbol);

        // 1. Detail page renders accurate metadata, price unit, and Contract Size (no stock Market Cap)
        $page = $this->get("/futures/{$slug}");
        $page->assertOk()
            ->assertSee($symbol)
            ->assertSee($unit)
            ->assertSeeHtml('data-testid="futures-header-contract-size"')
            ->assertDontSeeHtml('data-testid="stock-market-cap"')
            ->assertSeeHtml('data-testid="vela-chart-container"');

        // 2. Daily candles endpoint returns valid sorted candles with positive prices
        $dailyResp = $this->getJson("/futures/{$slug}/candles?interval=1day&from=2026-11-01&to=2026-11-05");
        $dailyResp->assertOk()
            ->assertJsonPath('symbol', $symbol)
            ->assertJsonPath('interval', '1day');

        $dailyCandles = $dailyResp->json('candles');
        expect($dailyCandles)->toHaveCount(2);
        foreach ($dailyCandles as $c) {
            expect($c['open'])->toBeGreaterThan(0)
                ->and($c['high'])->toBeGreaterThanOrEqual($c['low'])
                ->and($c['close'])->toBeGreaterThan(0)
                ->and($c['time'])->toBeGreaterThan(1700000000);
        }

        // 3. Intraday 1hour and 4hour candles endpoints work
        $hourlyResp = $this->getJson("/futures/{$slug}/candles?interval=1hour&from=2026-11-01&to=2026-11-05");
        $hourlyResp->assertOk();
        expect(count($hourlyResp->json('candles')))->toBeGreaterThanOrEqual(2);

        $fourHourResp = $this->getJson("/futures/{$slug}/candles?interval=4hour&from=2026-11-01&to=2026-11-05");
        $fourHourResp->assertOk();
        expect($fourHourResp->json('candles'))->toHaveCount(2);
    }
});

test('PersistentMarketDataProvider does not poison MarketDataPayload with a 24h empty bars record when upstream fails with HTTP 402 or 500', function () {
    Cache::flush();
    config(['market_data.futures.yahoo_fallback_enabled' => false]);

    Http::swap(new \Illuminate\Http\Client\Factory);
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        $attempt++;
        if ($attempt === 1) {
            return Http::response('Subscription tier error', 402);
        }

        return Http::response([
            ['date' => '2026-11-04', 'open' => 70.0, 'high' => 72.0, 'low' => 69.5, 'close' => 71.5, 'volume' => 100000],
        ], 200);
    });

    $fmp = new FmpMarketDataProvider('https://financialmodelingprep.com/stable', 'live-key');
    $persistent = new PersistentMarketDataProvider($fmp);

    $from = CarbonImmutable::parse('2026-11-01');
    $to = CarbonImmutable::parse('2026-11-05');

    // Attempt 1 fails with HTTP 402
    $bars1 = $persistent->historicalDailyBars('CLUSD', $from, $to);
    expect($bars1)->toBeEmpty();

    // Verify no empty 'bars' payload was persisted in MarketDataPayload
    $poisoned = MarketDataPayload::where('symbol', 'CLUSD')->where('data_type', 'bars')->first();
    expect($poisoned)->toBeNull();

    // Attempt 2 immediately retries upstream and succeeds
    $fmp->clearErrors();
    $bars2 = $persistent->historicalDailyBars('CLUSD', $from, $to);
    expect($bars2)->toHaveCount(1)
        ->and($bars2[0]['close'])->toBe(71.5);
});

test('futures candle normalization filters out zero/negative/null prices, preserves unadjusted contract rollover splices, and evaluates quote freshness accurately', function () {
    Cache::flush();

    $rollDay1Ts = CarbonImmutable::parse('2026-10-28 12:00:00', 'America/New_York')->utc()->timestamp;
    $rollDay2Ts = CarbonImmutable::parse('2026-10-29 12:00:00', 'America/New_York')->utc()->timestamp;
    $zeroDayTs = CarbonImmutable::parse('2026-10-30 12:00:00', 'America/New_York')->utc()->timestamp;

    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(function ($request) use ($rollDay1Ts, $rollDay2Ts, $zeroDayTs) {
        $url = (string) $request->url();
        if (str_contains($url, 'financialmodelingprep.com')) {
            return Http::response('Restricted', 402);
        }

        // Yahoo response includes a valid rollover step and a corrupted 0-price bar
        return Http::response(makeYahooChartPayload('CL=F', 72.10, 69.50, $rollDay2Ts, [
            ['ts' => $rollDay1Ts, 'open' => 68.80, 'high' => 69.90, 'low' => 68.50, 'close' => 69.50, 'volume' => 110000],
            ['ts' => $rollDay2Ts, 'open' => 71.20, 'high' => 72.40, 'low' => 70.90, 'close' => 72.10, 'volume' => 145000],
            ['ts' => $zeroDayTs, 'open' => 0.0, 'high' => 0.0, 'low' => 0.0, 'close' => 0.0, 'volume' => 0],
        ]), 200);
    });

    $fmp = new FmpMarketDataProvider('https://financialmodelingprep.com/stable', 'live-key');
    $bars = $fmp->historicalDailyBars('CLUSD', CarbonImmutable::parse('2026-10-25'), CarbonImmutable::parse('2026-11-01'));

    // Zero-price bar is rejected; unadjusted front-month rollover prices (69.50 -> 72.10) are preserved intact
    expect($bars)->toHaveCount(2)
        ->and($bars[0]['close'])->toBe(69.5)
        ->and($bars[1]['close'])->toBe(72.1);

    // Verify quote freshness classifications during open and closed CME Globex windows
    $recentService = app(\App\Services\Dashboard\RecentFuturesService::class);
    $openWed = CarbonImmutable::parse('2026-11-04 14:00:00', 'America/New_York')->utc();
    $closedSat = CarbonImmutable::parse('2026-11-07 14:00:00', 'America/New_York')->utc();

    expect($recentService->evaluateFreshness($openWed->subMinutes(5)->timestamp, true, false, $openWed)['status'])->toBe('live')
        ->and($recentService->evaluateFreshness($openWed->subMinutes(30)->timestamp, true, false, $openWed)['status'])->toBe('delayed')
        ->and($recentService->evaluateFreshness($openWed->subHours(3)->timestamp, true, false, $openWed)['status'])->toBe('stale')
        ->and($recentService->evaluateFreshness($closedSat->subHours(20)->timestamp, true, false, $closedSat)['status'])->toBe('market_closed')
        ->and($recentService->evaluateFreshness(null, false, false, $openWed)['status'])->toBe('unavailable');
});

test('external futures symbol aliases such as CLUSDT resolve to canonical FMP symbols via the FMP Commodities List API (/commodities-list)', function () {
    Cache::flush();

    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(function ($request) {
        $url = (string) $request->url();

        if (str_contains($url, '/commodities-list')) {
            return Http::response([
                ['symbol' => 'CLUSD', 'name' => 'Crude Oil', 'exchange' => null, 'tradeMonth' => 'Dec', 'currency' => 'USD'],
                ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'exchange' => null, 'tradeMonth' => 'Dec', 'currency' => 'USD'],
                ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'exchange' => null, 'tradeMonth' => 'Dec', 'currency' => 'USD'],
                ['symbol' => 'NGUSD', 'name' => 'Natural Gas', 'exchange' => null, 'tradeMonth' => 'Dec', 'currency' => 'USD'],
                ['symbol' => 'HGUSD', 'name' => 'Copper', 'exchange' => null, 'tradeMonth' => 'Dec', 'currency' => 'USD'],
                ['symbol' => 'LBRUSD', 'name' => 'Random Length Lumber Futures', 'exchange' => null, 'tradeMonth' => 'Jan', 'currency' => 'USD'],
            ], 200);
        }

        if (str_contains($url, '/search-symbol')) {
            return Http::response([], 200);
        }

        if (str_contains($url, '/quote') && str_contains($url, 'symbol=CLUSD')) {
            return Http::response([[
                'symbol' => 'CLUSD',
                'name' => 'Crude Oil',
                'price' => 71.85,
                'change' => 0.95,
                'changePercentage' => 1.34,
                'volume' => 132000,
                'exchange' => 'COMMODITY',
                'timestamp' => 1762372800,
            ]], 200);
        }

        if (str_contains($url, '/historical-price-eod/full') && str_contains($url, 'symbol=CLUSD')) {
            return Http::response([
                ['date' => '2026-11-04', 'open' => 70.90, 'high' => 72.10, 'low' => 70.50, 'close' => 71.85, 'volume' => 132000],
            ], 200);
        }

        return Http::response([], 200);
    });

    $fmp = new FmpMarketDataProvider('https://financialmodelingprep.com/stable', 'live-key');
    $persistent = new PersistentMarketDataProvider($fmp);
    app()->instance(MarketDataProvider::class, $persistent);

    $classifier = app(\App\Services\Stocks\InstrumentClassifier::class);

    // 1. CLUSDT and dynamically discovered *USDT symbols from FMP /commodities-list normalize to canonical FMP *USD symbols
    expect($classifier->normalizeFuturesSymbol('CLUSDT'))->toBe('CLUSD')
        ->and($classifier->normalizeFuturesSymbol('clusdt'))->toBe('CLUSD')
        ->and($classifier->normalizeFuturesSymbol('LBRUSDT'))->toBe('LBRUSD')
        ->and($classifier->classify('CLUSDT'))->toBe(\App\Services\Stocks\InstrumentClassifier::TYPE_FUTURES)
        ->and($classifier->resolve('CLUSDT')['symbol'])->toBe('CLUSD')
        ->and($classifier->resolve('CLUSDT')['canonical_url'])->toBe('/futures/clusd');

    // 2. FMP searchSymbols uses FMP Commodities List API (/commodities-list) to match CLUSDT, CLUSD, and commodity names
    $searchClusdt = $fmp->searchSymbols('CLUSDT');
    expect($searchClusdt)->not->toBeEmpty()
        ->and($searchClusdt[0]['symbol'])->toBe('CLUSD')
        ->and($searchClusdt[0]['type'])->toBe('Futures');

    $searchCrude = $fmp->searchSymbols('Crude Oil');
    expect($searchCrude)->not->toBeEmpty()
        ->and($searchCrude[0]['symbol'])->toBe('CLUSD');

    // 3. Quote and historical bars requested with CLUSDT resolve to canonical FMP symbol CLUSD
    $quote = $persistent->quote('CLUSDT');
    expect($quote)->toBeArray()
        ->and($quote['symbol'])->toBe('CLUSD')
        ->and($quote['price'])->toBe(71.85);

    $bars = $persistent->historicalDailyBars('CLUSDT', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-05'));
    expect($bars)->toHaveCount(1)
        ->and($bars[0]['close'])->toBe(71.85);

    // 4. Web routes and API routes redirect/resolve CLUSDT to canonical /futures/clusd and CLUSD
    $user = \App\Models\User::factory()->create([
        'role' => \App\Enums\UserRole::FUTURES,
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $this->get('/stock/clusdt')
        ->assertStatus(301)
        ->assertRedirect('/futures/clusd');

    $this->get('/futures/clusdt')
        ->assertStatus(301)
        ->assertRedirect('/futures/clusd');

    $this->getJson('/futures/clusdt/candles?interval=1day&from=2026-11-01&to=2026-11-05')
        ->assertOk()
        ->assertJsonPath('symbol', 'CLUSD');

    $this->getJson('/api/v1/market-data/CLUSDT/quote')
        ->assertOk()
        ->assertJsonPath('symbol', 'CLUSD')
        ->assertJsonPath('quote.price', 71.85);

    $this->getJson('/api/v1/market-data/commodities-list')
        ->assertOk()
        ->assertJsonPath('count', 6)
        ->assertJsonPath('commodities.0.symbol', 'CLUSD');

    // 5. RecentFuturesService normalizes CLUSDT visits to canonical CLUSD
    $recentService = app(\App\Services\Dashboard\RecentFuturesService::class);
    $recentService->recordVisit('CLUSDT', $user);
    expect($recentService->getRecentSymbols($user))->toContain('CLUSD')
        ->and($recentService->getRecentSymbols($user))->not->toContain('CLUSDT');
});
