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
    $user = User::factory()->create(['email_verified_at' => now()]);

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
    $user = User::factory()->create(['email_verified_at' => now()]);

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
    $user = User::factory()->create(['email_verified_at' => now()]);

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
    $user = User::factory()->create(['email_verified_at' => now()]);

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
    $user = User::factory()->create(['email_verified_at' => now()]);

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
    $user = User::factory()->create(['email_verified_at' => now()]);

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
