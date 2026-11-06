<?php

use App\Models\Currency;
use App\Models\User;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\FmpMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    Currency::factory()->create([
        'currency_code' => 'CAD',
        'name' => 'Canadian Dollar',
        'symbol' => '$',
        'scale' => 2,
        'display_scale' => 2,
        'type' => 'fiat',
        'is_active' => true,
    ]);
});

test('stock routes /stock/aapl and /stock/nbis render existing stock dashboard unchanged', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->profiles['AAPL'] = [
        'symbol' => 'AAPL',
        'company_name' => 'Apple Inc.',
        'exchange' => 'NASDAQ',
        'currency' => 'USD',
        'market_cap' => 3000000000000,
    ];
    $fake->quotes['AAPL'] = [
        'symbol' => 'AAPL',
        'price' => 225.40,
        'daily_change' => 2.10,
        'daily_change_percent' => 0.94,
        'volume' => 42000000,
        'market_cap' => 3000000000000,
    ];
    $fake->profiles['NBIS'] = [
        'symbol' => 'NBIS',
        'company_name' => 'Nebius Group N.V.',
        'exchange' => 'NASDAQ',
        'currency' => 'USD',
        'market_cap' => 12000000000,
    ];
    $fake->quotes['NBIS'] = [
        'symbol' => 'NBIS',
        'price' => 48.75,
        'daily_change' => 1.85,
        'daily_change_percent' => 3.94,
        'volume' => 6500000,
        'market_cap' => 12000000000,
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $aaplResp = $this->actingAs($user)->get('/stock/aapl');
    $aaplResp->assertOk()
        ->assertSee('AAPL')
        ->assertSee('Apple Inc.')
        ->assertSee('225.40')
        ->assertSeeHtml('data-testid="vela-chart-container"')
        ->assertSeeHtml('data-testid="news-timeline-panel"');

    $nbisResp = $this->actingAs($user)->get('/stock/nbis');
    $nbisResp->assertOk()
        ->assertSee('NBIS')
        ->assertSee('Nebius Group N.V.')
        ->assertSee('48.75');
});

test('/stock/gcusd and /stocks/gcusd permanently redirect (301) to /futures/gcusd with case-insensitive recognition', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    app()->instance(MarketDataProvider::class, $fake);

    $this->actingAs($user)->get('/stock/gcusd')
        ->assertStatus(301)
        ->assertRedirect('/futures/gcusd');

    $this->actingAs($user)->get('/stock/GCUSD')
        ->assertStatus(301)
        ->assertRedirect('/futures/gcusd');

    $this->actingAs($user)->get('/stocks/gcusd')
        ->assertStatus(301)
        ->assertRedirect('/futures/gcusd');

    $this->actingAs($user)->get('/stocks/GCUSD')
        ->assertStatus(301)
        ->assertRedirect('/futures/gcusd');
});

test('other commodity futures (silver, crude oil, natural gas, copper) redirect permanently to /futures/{symbol}', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    app()->instance(MarketDataProvider::class, $fake);

    foreach (['siusd', 'clusd', 'ngusd', 'hgusd'] as $commodity) {
        $this->actingAs($user)->get("/stock/{$commodity}")
            ->assertStatus(301)
            ->assertRedirect("/futures/{$commodity}");
    }
});

test('accessing a stock under /futures/{symbol} permanently redirects (301) to /stock/{symbol}', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->profiles['AAPL'] = [
        'symbol' => 'AAPL',
        'company_name' => 'Apple Inc.',
        'exchange' => 'NASDAQ',
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $this->actingAs($user)->get('/futures/AAPL')
        ->assertStatus(301)
        ->assertRedirect('/stock/aapl');
});

test('instrument classification discovers futures dynamically from FMP commoditiesList without symbol guessing', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->commodities = [
        [
            'symbol' => 'LBRUSD',
            'name' => 'Random Length Lumber Futures',
            'exchange' => 'COMMODITY',
            'trade_month' => 'Jan',
            'currency' => 'USD',
        ],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $this->actingAs($user)->get('/stock/LBRUSD')
        ->assertStatus(301)
        ->assertRedirect('/futures/lbrusd');

    $this->actingAs($user)->get('/futures/lbrusd')
        ->assertOk()
        ->assertSee('LBRUSD')
        ->assertSee('Random Length Lumber Futures');
});

test('/futures/gcusd displays gold futures dashboard with Vela chart, price units, and contract metadata', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['GCUSD'] = [
        'symbol' => 'GCUSD',
        'company_name' => 'Gold Futures',
        'price' => 4068.40,
        'daily_change' => 128.00,
        'daily_change_percent' => 3.25,
        'volume' => 223517,
        'exchange' => 'COMMODITY',
    ];
    $fake->dailyBars['GCUSD'] = [
        ['date' => '2026-10-29', 'open' => 3910.0, 'high' => 3955.0, 'low' => 3900.0, 'close' => 3940.4, 'volume' => 195000],
        ['date' => '2026-10-30', 'open' => 3940.8, 'high' => 4081.2, 'low' => 3928.2, 'close' => 4068.4, 'volume' => 223517],
    ];
    $fake->forexNewsRows['XAUUSD'] = [
        [
            'symbol' => 'XAUUSD',
            'publishedDate' => '2026-10-30 14:15:00',
            'publisher' => 'Reuters',
            'site' => 'reuters.com',
            'title' => 'Gold surges as Treasury yields retreat after Federal Reserve commentary',
            'text' => 'Gold futures climbed over 3% on COMEX after the Federal Reserve signaled patience on interest rates at 14:00 ET.',
            'url' => 'https://www.reuters.com/markets/commodities/gold-surges-after-fed-2026-10-30/',
        ],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/futures/gcusd');
    $response->assertOk()
        ->assertSee('GCUSD')
        ->assertSee('Gold Futures')
        ->assertSee('4,068.40')
        ->assertSee('USD / troy oz')
        ->assertSee('Front Contract')
        ->assertSeeHtml('data-testid="instrument-asset-type"')
        ->assertSeeHtml('data-testid="instrument-price-unit"')
        ->assertSeeHtml('data-testid="futures-contract-info"')
        ->assertSeeHtml('data-testid="vela-chart-container"')
        ->assertSeeHtml('data-testid="news-timeline-panel"')
        ->assertSeeHtml('data-testid="toggle-news-markers"')
        ->assertSeeHtml('data-testid="toggle-sync-chart-range"')
        ->assertSee('Gold surges as Treasury yields retreat after Federal Reserve commentary');
});

test('futures candles endpoint supports daily, 5min, 1hour, and 4hour intervals for GCUSD', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['GCUSD'] = [
        ['date' => '2026-10-30', 'open' => 3940.8, 'high' => 4081.2, 'low' => 3928.2, 'close' => 4068.4, 'volume' => 223517],
    ];
    $fake->intradayBars['GCUSD:5min'] = [
        ['date' => '2026-10-30 10:00:00', 'open' => 3980.0, 'high' => 3995.0, 'low' => 3978.0, 'close' => 3992.5, 'volume' => 1450],
        ['date' => '2026-10-30 10:05:00', 'open' => 3992.5, 'high' => 4010.0, 'low' => 3990.0, 'close' => 4008.0, 'volume' => 2100],
    ];
    $fake->intradayBars['GCUSD:1hour'] = [
        ['date' => '2026-10-30 10:00:00', 'open' => 3980.0, 'high' => 4025.0, 'low' => 3978.0, 'close' => 4020.0, 'volume' => 18500],
    ];
    $fake->intradayBars['GCUSD:4hour'] = [
        ['date' => '2026-10-30 08:00:00', 'open' => 3950.0, 'high' => 4030.0, 'low' => 3945.0, 'close' => 4020.0, 'volume' => 54000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    foreach (['1day' => 1, '5min' => 2, '1hour' => 1, '4hour' => 1] as $interval => $expectedCount) {
        $resp = $this->actingAs($user)->get("/futures/gcusd/candles?interval={$interval}&from=2026-10-29&to=2026-10-31");
        $resp->assertOk();
        expect($resp->json('symbol'))->toBe('GCUSD');
        expect($resp->json('interval'))->toBe($interval);
        expect($resp->json('candles'))->toHaveCount($expectedCount);
    }
});

test('news endpoint filters by relevance, deduplicates syndicated articles, and separates event time from publication time', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->forexNewsRows['XAUUSD'] = [
        // Relevant gold + Fed article with explicit earlier event time ("at 14:00 ET")
        [
            'symbol' => 'XAUUSD',
            'publishedDate' => '2026-10-30 14:25:00',
            'publisher' => 'FX Street',
            'site' => 'fxstreet.com',
            'title' => 'Gold bullion jumps above $4,050 after Federal Reserve FOMC statement',
            'text' => 'The Federal Reserve released its policy decision at 14:00 ET, sending Treasury yields lower and gold higher.',
            'url' => 'https://www.fxstreet.com/news/gold-bullion-jumps-after-fomc-20261030',
        ],
        // Duplicate syndicated article published 10 minutes later (should merge & retain earliest 14:25 publication time)
        [
            'symbol' => 'XAUUSD',
            'publishedDate' => '2026-10-30 14:35:00',
            'publisher' => 'Syndicated Wire',
            'site' => 'wire.example.com',
            'title' => 'Gold bullion jumps above $4,050 after Federal Reserve FOMC statement!',
            'text' => 'Central bank gold purchases also supported bullion demand.',
            'url' => 'https://wire.example.com/gold-bullion-jumps',
        ],
    ];
    $fake->generalNewsRows = [
        // Relevant geopolitical oil/tanker story
        [
            'symbol' => null,
            'publishedDate' => '2026-10-30 11:00:00',
            'publisher' => 'MarketWatch',
            'site' => 'marketwatch.com',
            'title' => 'Tanker incidents reported near the Strait of Hormuz lift crude oil and safe-haven gold',
            'text' => 'Geopolitical tensions escalated in the Middle East as oil supply concerns boosted bullion.',
            'url' => 'https://www.marketwatch.com/story/strait-of-hormuz-tanker-2026-10-30',
        ],
        // Unrelated celebrity/sports story (must be filtered out)
        [
            'symbol' => null,
            'publishedDate' => '2026-10-30 12:00:00',
            'publisher' => 'Lifestyle Daily',
            'site' => 'lifestyle.example.com',
            'title' => 'Local baking championship announces autumn pastry finalists',
            'text' => 'Twelve amateur bakers competed in the annual regional tournament.',
            'url' => 'https://lifestyle.example.com/baking-finalists',
        ],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/futures/gcusd/news?from=2026-10-30&to=2026-10-30');
    $response->assertOk();

    $data = $response->json();
    expect($data['symbol'])->toBe('GCUSD');
    expect($data['asset_type'])->toBe('futures');
    // 2 unique relevant stories (duplicate merged, unrelated filtered out)
    expect($data['count'])->toBe(2);
    expect($data['markers'])->toHaveCount(2);

    $fomcItem = $data['items'][0];
    expect($fomcItem['title'])->toContain('Gold bullion jumps above $4,050');
    // Earliest publication time preserved (14:25 EDT = 18:25 UTC)
    $expectedPubTs = \Carbon\Carbon::parse('2026-10-30 14:25:00', 'America/New_York')->utc()->timestamp;
    $expectedEventTs = \Carbon\Carbon::parse('2026-10-30 14:00:00', 'America/New_York')->utc()->timestamp;

    expect($fomcItem['published_timestamp'])->toBe($expectedPubTs);
    expect($fomcItem['marker_timestamp'])->toBe($expectedPubTs);
    expect($fomcItem['event_timestamp'])->toBe($expectedEventTs);
    expect($fomcItem['has_distinct_event_time'])->toBeTrue();
    expect($fomcItem['category_keys'])->toContain('gold_precious_metals', 'fed_monetary_policy', 'central_bank_gold');
});

test('news endpoint supports candle investigation with pre-movement and clearly labeled look-ahead window', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $candleTs = \Carbon\Carbon::parse('2026-10-30 14:00:00', 'America/New_York')->utc()->timestamp;

    $fake->forexNewsRows['XAUUSD'] = [
        [
            'symbol' => 'XAUUSD',
            'publishedDate' => '2026-10-30 13:30:00', // 30m before candle
            'publisher' => 'Reuters',
            'title' => 'US Dollar surges ahead of afternoon Treasury bond auction, pressuring gold',
            'text' => 'Rising Treasury yields weighed on precious metals.',
            'url' => 'https://example.com/pre-candle',
        ],
        [
            'symbol' => 'XAUUSD',
            'publishedDate' => '2026-10-30 14:45:00', // 45m after candle (look-ahead)
            'publisher' => 'Bloomberg',
            'title' => 'Gold drops 2% as real yields spike following weak 10-year Treasury auction',
            'text' => 'Analysts cited the sudden jump in yields for the sharp bullion sell-off.',
            'url' => 'https://example.com/post-candle',
        ],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get("/futures/gcusd/news?around_timestamp={$candleTs}&pre_window_minutes=120&look_ahead_minutes=90");
    $response->assertOk();

    $items = $response->json('items');
    expect($items)->toHaveCount(2);

    $postItem = collect($items)->firstWhere('url', 'https://example.com/post-candle');
    $preItem = collect($items)->firstWhere('url', 'https://example.com/pre-candle');

    expect($preItem['window_phase'])->toBe('before_or_during');
    expect($preItem['offset_minutes'])->toBe(-30);

    expect($postItem['window_phase'])->toBe('look_ahead');
    expect($postItem['offset_minutes'])->toBe(45);
});

test('news impact endpoint computes observed 5m, 15m, 30m, 60m, and 4h price changes and compares against WTI crude oil and silver', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    // Event at 2026-10-30 10:00:00 America/New_York (14:00:00 UTC)
    $eventTs = \Carbon\Carbon::parse('2026-10-30 10:00:00', 'America/New_York')->utc()->timestamp;

    $fake->intradayBars['GCUSD:5min'] = [
        ['date' => '2026-10-30 10:00:00', 'open' => 4000.0, 'high' => 4005.0, 'low' => 3998.0, 'close' => 4000.0, 'volume' => 1000],
        ['date' => '2026-10-30 10:05:00', 'open' => 4000.0, 'high' => 4012.0, 'low' => 4000.0, 'close' => 4010.0, 'volume' => 1500], // +5m: +0.25%
        ['date' => '2026-10-30 10:15:00', 'open' => 4010.0, 'high' => 4025.0, 'low' => 4008.0, 'close' => 4020.0, 'volume' => 1800], // +15m: +0.50%
        ['date' => '2026-10-30 10:30:00', 'open' => 4020.0, 'high' => 4035.0, 'low' => 4018.0, 'close' => 4030.0, 'volume' => 2100], // +30m: +0.75%
        ['date' => '2026-10-30 11:00:00', 'open' => 4030.0, 'high' => 4050.0, 'low' => 4025.0, 'close' => 4040.0, 'volume' => 2500], // +60m: +1.00%
        ['date' => '2026-10-30 14:00:00', 'open' => 4055.0, 'high' => 4090.0, 'low' => 4050.0, 'close' => 4080.0, 'volume' => 3200], // +4h: +2.00%
    ];

    // Related market: WTI Crude Oil (CLUSD)
    $fake->intradayBars['CLUSD:5min'] = [
        ['date' => '2026-10-30 10:00:00', 'open' => 70.00, 'high' => 70.20, 'low' => 69.90, 'close' => 70.00, 'volume' => 800],
        ['date' => '2026-10-30 10:05:00', 'open' => 70.00, 'high' => 70.80, 'low' => 70.00, 'close' => 70.70, 'volume' => 1200], // +5m: +1.00%
        ['date' => '2026-10-30 11:00:00', 'open' => 71.00, 'high' => 71.50, 'low' => 70.90, 'close' => 71.40, 'volume' => 1900], // +60m: +2.00%
    ];

    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get("/futures/gcusd/news-impact?timestamp={$eventTs}&compare=CLUSD,SIUSD");
    $response->assertOk();

    $data = $response->json();
    expect($data['symbol'])->toBe('GCUSD');
    expect($data['primary']['baseline_available'])->toBeTrue();
    expect($data['primary']['baseline_price'])->toEqual(4000.0);

    $horizons = collect($data['primary']['horizons'])->keyBy('key');
    expect($horizons['5m']['available'])->toBeTrue();
    expect($horizons['5m']['change'])->toEqual(10.0);
    expect($horizons['5m']['change_percent'])->toEqual(0.25);

    expect($horizons['15m']['change_percent'])->toEqual(0.5);
    expect($horizons['30m']['change_percent'])->toEqual(0.75);
    expect($horizons['60m']['change_percent'])->toEqual(1.0);
    expect($horizons['4h']['change_percent'])->toEqual(2.0);

    // Verify related market comparisons (CLUSD has data, SIUSD handles missing data gracefully)
    $comparisons = collect($data['comparisons'])->keyBy('symbol');
    expect($comparisons)->toHaveKeys(['CLUSD', 'SIUSD']);
    expect($comparisons['CLUSD']['baseline_available'])->toBeTrue();
    $clHorizons = collect($comparisons['CLUSD']['horizons'])->keyBy('key');
    expect($clHorizons['5m']['change_percent'])->toEqual(1.0);
    expect($clHorizons['60m']['change_percent'])->toEqual(2.0);

    // SIUSD has no intraday bars populated -> gracefully marked unavailable
    expect($comparisons['SIUSD']['baseline_available'])->toBeFalse();
    expect($comparisons['SIUSD']['horizons'][0]['available'])->toBeFalse();
});

test('FmpMarketDataProvider commoditiesList and quote handle commodity futures without unnecessary profile calls', function () {
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, '/commodities-list')) {
            return Http::response([
                ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'exchange' => null, 'tradeMonth' => 'Dec', 'currency' => 'USD'],
                ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'exchange' => null, 'tradeMonth' => 'Dec', 'currency' => 'USD'],
            ], 200);
        }
        if (str_contains($url, '/quote')) {
            return Http::response([[
                'symbol' => 'GCUSD',
                'name' => 'Gold Futures',
                'price' => 4068.4,
                'change' => 128.0,
                'changePercentage' => 3.2484,
                'volume' => 223517,
                'exchange' => 'COMMODITY',
                'open' => 3940.8,
                'previousClose' => 3940.4,
                'dayLow' => 3928.2,
                'dayHigh' => 4081.2,
            ]], 200);
        }

        return Http::response([], 200);
    });

    $fmp = new FmpMarketDataProvider('https://test.local/stable', 'test-key');
    $list = $fmp->commoditiesList();
    expect($list)->toHaveCount(2);
    expect($list[0]['symbol'])->toBe('GCUSD');
    expect($list[0]['trade_month'])->toBe('Dec');

    $quote = $fmp->quote('GCUSD');
    expect($quote['symbol'])->toBe('GCUSD');
    expect($quote['price'])->toBe(4068.4);
    expect($quote['exchange'])->toBe('COMMODITY');
    expect($quote['company_name'])->toBe('Gold Futures');
});

test('application-bound PersistentMarketDataProvider delegates all news methods to FmpMarketDataProvider', function () {
    config([
        'market_data.provider' => 'fmp',
        'market_data.fmp.base_url' => 'https://fmp.test/stable',
        'market_data.fmp.api_key' => 'test-api-key',
        'market_data.news.fed_rss_enabled' => false,
        'market_data.news.sources.searxng_enabled' => false,
    ]);

    // Re-resolve MarketDataProvider through the service container just like production
    app()->forgetInstance(MarketDataProvider::class);
    $boundProvider = app(MarketDataProvider::class);
    expect($boundProvider)->toBeInstanceOf(\App\Services\MarketData\PersistentMarketDataProvider::class);

    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, '/profile')) {
            return Http::response([[
                'symbol' => 'NBIS',
                'companyName' => 'Nebius Group N.V.',
                'exchangeShortName' => 'NASDAQ',
                'currency' => 'USD',
                'sector' => 'Technology',
                'industry' => 'Software - Application',
            ]], 200);
        }
        if (str_contains($url, '/news/stock')) {
            return Http::response([
                [
                    'symbol' => 'NBIS',
                    'publishedDate' => '2026-11-03 16:26:01',
                    'publisher' => 'The Motley Fool',
                    'site' => 'fool.com',
                    'title' => 'Nebius Stock Is Down 30%. Could Its $9 Billion ARR Goal Spark a Rebound?',
                    'text' => 'Nebius (NBIS) is racing to expand its AI cloud business with major agreements.',
                    'url' => 'https://www.fool.com/investing/2026/11/03/nebius-stock-rebound/',
                ],
            ], 200);
        }
        if (str_contains($url, '/news/press-releases')) {
            return Http::response([
                [
                    'symbol' => 'NBIS',
                    'publishedDate' => '2026-10-22 07:00:00',
                    'publisher' => 'Business Wire',
                    'site' => 'businesswire.com',
                    'title' => 'Nebius secures $3.5 billion financing facility with Citi',
                    'text' => 'Nebius Group N.V. (Nasdaq: NBIS), the AI cloud company, today announced a $3.5 billion facility.',
                    'url' => 'https://www.businesswire.com/news/nebius-citi-facility',
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    $user = User::factory()->create(['email_verified_at' => now()]);
    $resp = $this->actingAs($user)->get('/stocks/NBIS/news');
    $resp->assertOk();

    $data = $resp->json();
    expect($data['status'])->toBe('available');
    expect($data['count'])->toBe(2);
    expect($data['items'][0]['title'])->toContain('Nebius Stock Is Down 30%');
    expect($data['items'][1]['title'])->toContain('Nebius secures $3.5 billion financing facility');
});

test('stock relevance filtering rejects cross-exchange ticker collisions (MASS), name collisions (CRON), and untargeted market roundups (NBIS)', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $fake = new FakeMarketDataProvider;

    $fake->profiles['MASS'] = [
        'symbol' => 'MASS',
        'company_name' => '908 Devices Inc.',
        'exchange' => 'NASDAQ',
        'sector' => 'Healthcare',
        'industry' => 'Medical - Specialties',
    ];
    $fake->stockNewsRows['MASS'] = [
        [
            'symbol' => 'MASS',
            'publishedDate' => '2026-10-27 07:00:00',
            'publisher' => 'Business Wire',
            'title' => '908 Devices to Report Third Quarter 2026 Financial Results on November 10, 2026',
            'text' => '908 Devices Inc. (Nasdaq: MASS) focused on handheld chemical detection tools.',
            'url' => 'https://example.com/908-devices-q3',
        ],
        [
            'symbol' => 'MASS',
            'publishedDate' => '2026-09-29 10:15:00',
            'publisher' => 'Newsfile',
            'title' => 'Masivo Silver Reports Broad Polymetallic Mineralization from Initial 2026 Drilling Program',
            'text' => 'Masivo Silver Corp. (TSXV: MASS) reports drilling results in Sonora, Mexico.',
            'url' => 'https://example.com/masivo-silver-collision',
        ],
    ];

    $fake->profiles['CRON'] = [
        'symbol' => 'CRON',
        'company_name' => 'Cronos Group Inc.',
        'exchange' => 'NASDAQ',
        'sector' => 'Healthcare',
        'industry' => 'Drug Manufacturers',
    ];
    $fake->stockNewsRows['CRON'] = [
        [
            'symbol' => 'CRON',
            'publishedDate' => '2026-10-15 07:00:00',
            'publisher' => 'GlobeNewsWire',
            'title' => 'Cronos Completes Acquisition of CanAdelaar, Europe Largest Adult-Use Cannabis Company',
            'text' => 'Cronos Group Inc. (NASDAQ: CRON) acquires CanAdelaar.',
            'url' => 'https://example.com/cronos-canadelaar',
        ],
        [
            'symbol' => 'CRON',
            'publishedDate' => '2026-10-30 09:31:08',
            'publisher' => 'Zacks',
            'title' => 'Baker Hughes Wins Major Subsea Contract From Eni for Cronos Gas',
            'text' => 'Baker Hughes (NASDAQ: BKR) secures a subsea contract for Cyprus Cronos gas project.',
            'url' => 'https://example.com/baker-hughes-cronos-gas',
        ],
    ];

    $fake->profiles['NBIS'] = [
        'symbol' => 'NBIS',
        'company_name' => 'Nebius Group N.V.',
        'exchange' => 'NASDAQ',
    ];
    $fake->stockNewsRows['NBIS'] = [
        [
            'symbol' => 'NBIS',
            'publishedDate' => '2026-11-04 10:00:00',
            'publisher' => 'Reuters',
            'title' => 'Nebius expands AI cloud capacity across European data centers',
            'text' => 'Nebius Group (NASDAQ: NBIS) announced new GPU cluster deployments.',
            'url' => 'https://example.com/nebius-gpu',
        ],
        [
            'symbol' => 'NBIS',
            'publishedDate' => '2026-11-04 11:00:00',
            'publisher' => 'Generic Market Blog',
            'title' => 'Is the Market Bottoming? 3 Tells to Watch After Midterm Elections',
            'text' => 'While initial post-election capitulation signals a stock market bottom, investors watch earnings.',
            'url' => 'https://example.com/generic-market-bottom',
        ],
    ];

    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($fake));

    $massResp = $this->actingAs($user)->get('/stocks/MASS/news')->assertOk()->json();
    expect($massResp['count'])->toBe(1);
    expect($massResp['items'][0]['title'])->toContain('908 Devices');

    $cronResp = $this->actingAs($user)->get('/stocks/CRON/news')->assertOk()->json();
    expect($cronResp['count'])->toBe(1);
    expect($cronResp['items'][0]['title'])->toContain('CanAdelaar');

    $nbisResp = $this->actingAs($user)->get('/stocks/NBIS/news')->assertOk()->json();
    expect($nbisResp['count'])->toBe(1);
    expect($nbisResp['items'][0]['title'])->toContain('Nebius expands AI cloud');
});

test('futures relevance filtering rejects routine EUR/USD forecasts on GCUSD and uses commodity-specific profiles for CLUSD', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);
    $fake = new FakeMarketDataProvider;

    $fake->forexNewsRows['XAUUSD'] = [
        [
            'symbol' => 'XAUUSD',
            'publishedDate' => '2026-11-04 14:00:00',
            'publisher' => 'FX Street',
            'title' => 'Gold extends rally as US Treasury yields retreat after FOMC minutes',
            'text' => 'XAU/USD climbs above $4,060 on safe-haven demand.',
            'url' => 'https://example.com/xauusd-rally',
        ],
    ];
    $fake->forexNewsRows['EURUSD'] = [
        [
            'symbol' => 'EURUSD',
            'publishedDate' => '2026-11-04 15:00:00',
            'publisher' => 'FX Street',
            'title' => 'EUR/USD Price Forecast: Bulls await breakout above 1.1270 resistance',
            'text' => 'Routine technical outlook for the euro against the US dollar.',
            'url' => 'https://example.com/eurusd-forecast',
        ],
    ];
    $fake->generalNewsRows = [
        [
            'symbol' => null,
            'publishedDate' => '2026-11-04 16:00:00',
            'publisher' => 'Reuters',
            'title' => 'WTI crude oil jumps 3% as OPEC signals tighter supply quotas',
            'text' => 'Crude oil futures rose on NYMEX after refinery inventory draws.',
            'url' => 'https://example.com/wti-opec',
        ],
    ];

    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($fake));

    // GCUSD retains gold + geopolitical oil supply, but rejects EUR/USD technical forecast
    $gcusd = $this->actingAs($user)->get('/futures/gcusd/news')->assertOk()->json();
    $gcusdUrls = array_column($gcusd['items'], 'url');
    expect($gcusdUrls)->toContain('https://example.com/xauusd-rally');
    expect($gcusdUrls)->not->toContain('https://example.com/eurusd-forecast');

    // CLUSD uses crude_oil commodity profile and excludes pure gold articles that do not mention oil/macro policy
    $fake->forexNewsRows['XAUUSD'] = [
        [
            'symbol' => 'XAUUSD',
            'publishedDate' => '2026-11-04 14:00:00',
            'publisher' => 'FX Street',
            'title' => 'Gold bullion steady in quiet Asian bullion trading',
            'text' => 'Spot gold held near $4,050 per ounce in Shanghai.',
            'url' => 'https://example.com/pure-gold-only',
        ],
    ];
    $clusd = $this->actingAs($user)->get('/futures/clusd/news?refresh=1')->assertOk()->json();
    $clusdUrls = array_column($clusd['items'], 'url');
    expect($clusdUrls)->toContain('https://example.com/wti-opec');
    expect($clusdUrls)->not->toContain('https://example.com/pure-gold-only');
});

test('news service distinguishes empty responses from auth_error (403), rate_limited (429), service_unavailable (503), and missing provider methods', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    config(['market_data.news.sources.searxng_enabled' => false]);

    $fake = new FakeMarketDataProvider;
    $fake->profiles['AAPL'] = ['symbol' => 'AAPL', 'company_name' => 'Apple Inc.', 'exchange' => 'NASDAQ'];
    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($fake));

    // 1. Legitimate empty response
    $emptyResp = $this->actingAs($user)->get('/stocks/AAPL/news?refresh=1')->assertOk()->json();
    expect($emptyResp['status'])->toBe('empty');
    expect($emptyResp['diagnostics']['failure_category'])->toBeNull();

    // 2. Upstream 403 authorization / subscription error
    $fake->errors = [['path' => 'news/stock', 'status' => 403, 'body' => 'Subscription tier does not permit endpoint']];
    // Simulate error recorded during stockNews call
    $authProvider = new class($fake) extends FakeMarketDataProvider
    {
        public function __construct(protected FakeMarketDataProvider $inner) {}

        public function stockNews(array $symbols = [], ?\Carbon\CarbonInterface $from = null, ?\Carbon\CarbonInterface $to = null, int $limit = 50): array
        {
            $this->errors[] = ['path' => 'news/stock', 'status' => 403, 'body' => 'Forbidden'];

            return [];
        }
    };
    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($authProvider));

    $authResp = $this->actingAs($user)->get('/stocks/AAPL/news?refresh=1')->assertOk()->json();
    expect($authResp['status'])->toBe('auth_error');
    expect($authResp['diagnostics']['failure_category'])->toBe('auth_or_subscription_error');

    // 3. Upstream 429 rate limit exceeded
    $rateProvider = new class extends FakeMarketDataProvider
    {
        public function stockNews(array $symbols = [], ?\Carbon\CarbonInterface $from = null, ?\Carbon\CarbonInterface $to = null, int $limit = 50): array
        {
            $this->errors[] = ['path' => 'news/stock', 'status' => 429, 'body' => 'Rate limit exceeded'];

            return [];
        }
    };
    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($rateProvider));

    $rateResp = $this->actingAs($user)->get('/stocks/AAPL/news?refresh=1')->assertOk()->json();
    expect($rateResp['status'])->toBe('rate_limited');
    expect($rateResp['diagnostics']['failure_category'])->toBe('rate_limited');

    // 4. Provider missing news methods entirely surfaces missing_provider_method instead of silent empty feed
    $incompleteProvider = new class implements MarketDataProvider
    {
        public function earningsCalendar(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to, bool $fresh = false): array
        {
            return [];
        }

        public function earningsSurprises(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to, bool $fresh = false): array
        {
            return [];
        }

        public function quote(string $symbol): ?array
        {
            return null;
        }

        public function profile(string $symbol): ?array
        {
            return ['symbol' => $symbol, 'company_name' => 'Apple Inc.', 'exchange' => 'NASDAQ'];
        }

        public function analystEstimates(string $symbol, string $period = 'quarter'): array
        {
            return [];
        }

        public function quarterlyIncomeStatements(string $symbol, int $limit = 8): array
        {
            return [];
        }

        public function quarterlyBalanceSheets(string $symbol, int $limit = 8): array
        {
            return [];
        }

        public function quarterlyCashFlowStatements(string $symbol, int $limit = 8): array
        {
            return [];
        }

        public function companyScreener(array $filters = [], bool $fresh = false): array
        {
            return [];
        }

        public function historicalDailyBars(string $symbol, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array
        {
            return [];
        }

        public function historicalIntradayBars(string $symbol, string $interval, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array
        {
            return [];
        }

        public function lastErrors(): array
        {
            return [];
        }

        public function clearErrors(): void {}
    };

    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($incompleteProvider));
    $missingResp = $this->actingAs($user)->get('/stocks/AAPL/news?refresh=1')->assertOk()->json();
    expect($missingResp['status'])->toBe('service_unavailable');
    expect($missingResp['diagnostics']['failure_category'])->toBe('missing_provider_method');
});

test('historical chart-range filtering and persistent article storage work when navigating backward and forward in time', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $fake = new FakeMarketDataProvider;
    $fake->profiles['NBIS'] = ['symbol' => 'NBIS', 'company_name' => 'Nebius Group N.V.', 'exchange' => 'NASDAQ'];
    $fake->stockNewsRows['NBIS'] = [
        [
            'symbol' => 'NBIS',
            'publishedDate' => '2026-09-08 08:00:00',
            'publisher' => 'Business Wire',
            'title' => 'Palantir and Nebius Partner to Deliver Sovereign AI Stack',
            'text' => 'Nebius Group N.V. (NASDAQ: NBIS) announced a strategic AI partnership in September.',
            'url' => 'https://example.com/nebius-september',
        ],
        [
            'symbol' => 'NBIS',
            'publishedDate' => '2026-10-30 08:00:00',
            'publisher' => 'Business Wire',
            'title' => 'Nebius Group announces third quarter 2026 financial results date',
            'text' => 'Nebius Group N.V. (NASDAQ: NBIS) will report Q3 results.',
            'url' => 'https://example.com/nebius-october',
        ],
    ];
    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($fake));

    // Navigate backward to September 2026
    $sepResp = $this->actingAs($user)->get('/stocks/NBIS/news?from=2026-09-01&to=2026-09-15')->assertOk()->json();
    expect($sepResp['count'])->toBe(1);
    expect($sepResp['items'][0]['title'])->toContain('Palantir and Nebius Partner');

    // Navigate forward to October 2026
    $octResp = $this->actingAs($user)->get('/stocks/NBIS/news?from=2026-10-25&to=2026-10-31')->assertOk()->json();
    expect($octResp['count'])->toBe(1);
    expect($octResp['items'][0]['title'])->toContain('third quarter 2026 financial results');

    // Even if upstream feed later drops the September story, persisted DB storage retains it when querying September again
    $fake->stockNewsRows['NBIS'] = [];
    $sepPersisted = $this->actingAs($user)->get('/stocks/NBIS/news?from=2026-09-01&to=2026-09-15&refresh=1')->assertOk()->json();
    expect($sepPersisted['count'])->toBe(1);
    expect($sepPersisted['items'][0]['title'])->toContain('Palantir and Nebius Partner');
});

test('repeated requests for the same instrument and window share cached results without redundant provider calls', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $callCount = 0;

    $countingProvider = new class($callCount) extends FakeMarketDataProvider
    {
        public function __construct(public int &$counter) {}

        public function stockNews(array $symbols = [], ?\Carbon\CarbonInterface $from = null, ?\Carbon\CarbonInterface $to = null, int $limit = 50): array
        {
            $this->counter++;

            return [
                [
                    'symbol' => 'NBIS',
                    'publishedDate' => '2026-11-01 10:00:00',
                    'publisher' => 'Reuters',
                    'title' => 'Nebius Group reports strong Q3 AI cloud demand',
                    'text' => 'Nebius (NASDAQ: NBIS) revenue surged.',
                    'url' => 'https://example.com/nebius-shared-cache',
                ],
            ];
        }
    };
    $countingProvider->profiles['NBIS'] = ['symbol' => 'NBIS', 'company_name' => 'Nebius Group N.V.', 'exchange' => 'NASDAQ'];

    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($countingProvider));

    $first = $this->actingAs($user)->get('/stocks/NBIS/news?from=2026-11-01&to=2026-11-02')->assertOk()->json();
    $second = $this->actingAs($user)->get('/stocks/NBIS/news?from=2026-11-01&to=2026-11-02')->assertOk()->json();

    expect($first['count'])->toBe(1);
    expect($first['diagnostics']['cache_hit'])->toBeFalse();
    expect($second['count'])->toBe(1);
    expect($second['diagnostics']['cache_hit'])->toBeTrue();
    expect($callCount)->toBe(1);
});

test('SearXNG fallback discovers articles with valid timestamps, skips results without timestamps, and handles disabled JSON gracefully', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    config([
        'market_data.news.sources.searxng_enabled' => true,
        'market_data.news.sources.searxng_url' => 'http://searxng.test:8080',
        'market_data.news.sources.searxng_fallback_min_articles' => 5,
    ]);

    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'searxng.test:8080/search')) {
            return Http::response([
                'results' => [
                    [
                        'title' => 'Nebius Group signs new European sovereign AI cloud contract',
                        'content' => 'Nebius (NASDAQ: NBIS) expands capacity.',
                        'url' => 'https://example.com/searxng-nebius-valid',
                        'publishedDate' => '2026-11-03T14:00:00Z',
                        'engine' => 'google news',
                    ],
                    // Result missing publishedDate must NOT have a fabricated timestamp
                    [
                        'title' => 'Nebius undated search result that must be skipped',
                        'content' => 'Nebius (NASDAQ: NBIS) undated.',
                        'url' => 'https://example.com/searxng-nebius-undated',
                    ],
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    $fake = new FakeMarketDataProvider;
    $fake->profiles['NBIS'] = ['symbol' => 'NBIS', 'company_name' => 'Nebius Group N.V.', 'exchange' => 'NASDAQ'];
    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($fake));

    $resp = $this->actingAs($user)->get('/stocks/NBIS/news?force_searxng=1&refresh=1')->assertOk()->json();
    expect($resp['count'])->toBe(1);
    expect($resp['items'][0]['url'])->toBe('https://example.com/searxng-nebius-valid');
    expect($resp['diagnostics']['sources']['searxng']['status'])->toBe('ok');
});

test('optional Ollama enrichment attaches cached rationale when available and never blocks news when Ollama fails', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    config([
        'market_data.news.ai_enrichment.enabled' => true,
        'market_data.news.ai_enrichment.async' => false,
        'market_data.news.ai_enrichment.ollama_url' => 'http://ollama.test:11434',
        'market_data.news.ai_enrichment.model' => 'qwen3:8b',
        'market_data.news.sources.searxng_enabled' => false,
    ]);

    $fake = new FakeMarketDataProvider;
    $fake->profiles['NBIS'] = ['symbol' => 'NBIS', 'company_name' => 'Nebius Group N.V.', 'exchange' => 'NASDAQ'];
    $fake->stockNewsRows['NBIS'] = [
        [
            'symbol' => 'NBIS',
            'publishedDate' => '2026-11-03 12:00:00',
            'publisher' => 'Reuters',
            'title' => 'Nebius announces $3.5B AI infrastructure financing facility',
            'text' => 'Nebius Group N.V. (NASDAQ: NBIS) secured new credit lines.',
            'url' => 'https://example.com/nebius-ai-test',
        ],
    ];
    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($fake));

    Http::fake([
        'ollama.test:11434/*' => Http::sequence()
            ->push('Internal Server Error', 500)
            ->push([
                'response' => json_encode([
                    'results' => [
                        [
                            'idx' => 0,
                            'relevant' => true,
                            'market_moving' => true,
                            'why_it_matters' => 'Secures $3.5B capital for Nebius GPU cluster expansion.',
                        ],
                    ],
                ]),
            ], 200),
    ]);

    // Case 1: Ollama returns HTTP 500 -> news still succeeds without blocking
    $degradedResp = $this->actingAs($user)->get('/stocks/NBIS/news?refresh=1')->assertOk()->json();
    expect($degradedResp['status'])->toBe('available');
    expect($degradedResp['count'])->toBe(1);
    expect($degradedResp['items'][0]['ai_enriched'])->toBeFalse();

    // Case 2: Ollama returns valid JSON enrichment -> attached and cached
    $enrichedResp = $this->actingAs($user)->get('/stocks/NBIS/news?refresh=1')->assertOk()->json();
    expect($enrichedResp['items'][0]['ai_enriched'])->toBeTrue();
    expect($enrichedResp['items'][0]['ai_market_moving'])->toBeTrue();
    expect($enrichedResp['items'][0]['ai_rationale'])->toBe('Secures $3.5B capital for Nebius GPU cluster expansion.');
});

test('price-impact service does not report a 5-minute reaction when only 15-minute or 1-hour bars exist', function () {
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);
    $fake = new FakeMarketDataProvider;
    $eventTs = \Carbon\Carbon::parse('2026-10-30 10:00:00', 'America/New_York')->utc()->timestamp;

    // 5-minute series has baseline at 10:00 and a bar at 10:15 (+15m), but NO bar at 10:05 (+5m)
    $fake->intradayBars['GCUSD:5min'] = [
        ['date' => '2026-10-30 10:00:00', 'open' => 4000.0, 'high' => 4005.0, 'low' => 3998.0, 'close' => 4000.0, 'volume' => 1000],
        ['date' => '2026-10-30 10:15:00', 'open' => 4010.0, 'high' => 4025.0, 'low' => 4008.0, 'close' => 4020.0, 'volume' => 1800],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $resp = $this->actingAs($user)->get("/futures/gcusd/news-impact?timestamp={$eventTs}&compare=")->assertOk()->json();
    $horizons = collect($resp['primary']['horizons'])->keyBy('key');

    // 5m must be unavailable because no bar exists within +/- 3 minutes of +5m
    expect($horizons['5m']['available'])->toBeFalse();
    // 15m is available and reports 15.0 actual elapsed minutes
    expect($horizons['15m']['available'])->toBeTrue();
    expect($horizons['15m']['change_percent'])->toEqual(0.5);
    expect($horizons['15m']['actual_elapsed_minutes'])->toEqual(15.0);
});

test('feature-disable setting (market_data.news.enabled = false) disables news retrieval while keeping stock and futures dashboards operational', function () {
    config(['market_data.news.enabled' => false]);
    $user = User::factory()->fullSubscription()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['GCUSD'] = ['symbol' => 'GCUSD', 'price' => 4068.40, 'exchange' => 'COMMODITY'];
    app()->instance(MarketDataProvider::class, $fake);

    $newsJson = $this->actingAs($user)->get('/futures/gcusd/news')->assertOk()->json();
    expect($newsJson['enabled'])->toBeFalse();
    expect($newsJson['status'])->toBe('disabled');
    expect($newsJson['count'])->toBe(0);

    $dashboard = $this->actingAs($user)->get('/futures/gcusd');
    $dashboard->assertOk()
        ->assertSee('GCUSD')
        ->assertSeeHtml('data-testid="vela-chart-container"')
        ->assertDontSeeHtml('data-testid="news-timeline-panel"');
});

test('artisan news:verify command diagnoses provider methods and outputs retrieval summary', function () {
    $fake = new FakeMarketDataProvider;
    $fake->profiles['NBIS'] = ['symbol' => 'NBIS', 'company_name' => 'Nebius Group N.V.', 'exchange' => 'NASDAQ'];
    $fake->stockNewsRows['NBIS'] = [
        [
            'symbol' => 'NBIS',
            'publishedDate' => '2026-11-04 12:00:00',
            'publisher' => 'Reuters',
            'title' => 'Nebius Group expands AI cloud infrastructure',
            'text' => 'Nebius (NASDAQ: NBIS) added new capacity.',
            'url' => 'https://example.com/nebius-verify',
        ],
    ];
    app()->instance(MarketDataProvider::class, new \App\Services\MarketData\PersistentMarketDataProvider($fake));

    $this->artisan('news:verify NBIS --fresh')
        ->expectsOutputToContain('Provider News Methods    : OK')
        ->expectsOutputToContain('Nebius Group expands AI cloud infrastructure')
        ->assertExitCode(0);
});

test('opt-in live FMP integration smoke test retrieves genuine articles for NBIS and GCUSD', function () {
    if (! env('RUN_LIVE_NEWS_SMOKE_TEST')) {
        $this->markTestSkipped('Set RUN_LIVE_NEWS_SMOKE_TEST=1 to execute live FMP news smoke test.');
    }

    $liveKey = env('LIVE_FMP_API_KEY') ?: config('market_data.fmp.api_key');
    if ((! $liveKey || $liveKey === 'test-key') && is_file(base_path('.env'))) {
        $dotenv = \Dotenv\Dotenv::parse((string) file_get_contents(base_path('.env')));
        $liveKey = $dotenv['FMP_API_KEY'] ?? null;
    }

    if (! $liveKey || $liveKey === 'test-key') {
        $this->markTestSkipped('Valid FMP_API_KEY not configured for live smoke test.');
    }

    config([
        'market_data.provider' => 'fmp',
        'market_data.fmp.api_key' => $liveKey,
        'market_data.news.sources.searxng_enabled' => false,
    ]);
    app()->forgetInstance(MarketDataProvider::class);

    $svc = app(\App\Services\Stocks\InstrumentNewsService::class);
    $nbis = $svc->getNews('NBIS', ['refresh' => true, 'limit' => 10]);
    $gcusd = $svc->getNews('GCUSD', ['refresh' => true, 'limit' => 10]);

    expect($nbis['status'])->toBe('available');
    expect($nbis['count'])->toBeGreaterThan(0);
    expect($gcusd['status'])->toBe('available');
    expect($gcusd['count'])->toBeGreaterThan(0);
});
