<?php

use App\Models\CompanyProfile;
use App\Models\StockDailyBar;
use App\Models\User;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['market_data.api_key' => 'test-duoasset-key-12345']);
});

test('api routes reject unauthenticated requests without api key with 401', function () {
    $response = $this->getJson('/api/v1/market-data/AAPL/candles');

    $response->assertStatus(401)
        ->assertJson([
            'provider' => 'duoasset',
            'error' => 'Unauthenticated: valid session or API key required.',
        ]);
});

test('api routes reject api key in URL query parameter with 401', function () {
    $response = $this->getJson('/api/v1/market-data/AAPL/candles?api_key=test-duoasset-key-12345');

    $response->assertStatus(401)
        ->assertJson([
            'provider' => 'duoasset',
            'error' => 'Unauthenticated: valid session or API key required.',
        ]);
});

test('api routes authorize external clients via bearer token header', function () {
    StockDailyBar::create([
        'symbol' => 'AAPL',
        'bar_date' => '2026-10-01',
        'open' => 150.0,
        'high' => 155.0,
        'low' => 149.0,
        'close' => 154.0,
        'adj_close' => 154.0,
        'volume' => 1000000,
        'retrieved_at' => now(),
    ]);

    $response = $this->withToken('test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/AAPL/candles?interval=1day&from=2026-10-01&to=2026-10-01');

    $response->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'AAPL',
            'count' => 1,
        ]);
});

test('api routes reject authenticated users without stock domain permission with 403', function () {
    $cryptoUser = User::factory()->crypto()->create();

    $response = $this->actingAs($cryptoUser)->getJson('/api/v1/market-data/AAPL/candles');

    $response->assertStatus(403)
        ->assertJson([
            'provider' => 'duoasset',
            'error' => 'Forbidden: user account lacks permission to access market data.',
        ]);
});

test('api routes authorize authenticated users with stock access', function () {
    $equitiesUser = User::factory()->equities()->create();

    StockDailyBar::create([
        'symbol' => 'AAPL',
        'bar_date' => '2026-10-01',
        'open' => 150.0,
        'high' => 155.0,
        'low' => 149.0,
        'close' => 154.0,
        'adj_close' => 154.0,
        'volume' => 1000000,
        'retrieved_at' => now(),
    ]);

    $response = $this->actingAs($equitiesUser)
        ->getJson('/api/v1/market-data/AAPL/candles?interval=1day&from=2026-10-01&to=2026-10-01');

    $response->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'AAPL',
            'interval' => '1day',
            'count' => 1,
        ]);
});

test('api routes authorize external clients via x-api-key header', function () {
    StockDailyBar::create([
        'symbol' => 'AAPL',
        'bar_date' => '2026-10-01',
        'open' => 150.0,
        'high' => 155.0,
        'low' => 149.0,
        'close' => 154.0,
        'adj_close' => 154.0,
        'volume' => 1000000,
        'retrieved_at' => now(),
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/AAPL/candles?interval=1day&from=2026-10-01&to=2026-10-01');

    $response->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'AAPL',
            'count' => 1,
        ]);
});

test('api candles endpoint rejects invalid interval with 422', function () {
    $response = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/AAPL/candles?interval=unsupported_interval');

    $response->assertStatus(422)
        ->assertJsonFragment([
            'provider' => 'duoasset',
            'error' => 'Invalid interval parameter. Supported: 1min, 5min, 15min, 30min, 1hour, 1day, 1week, 1month.',
        ]);
});

test('api candles endpoint aggregates daily bars into weekly and monthly candles', function () {
    // Create daily bars across multiple weeks in October 2026:
    // Week 1 (Monday Oct 5 to Friday Oct 9)
    StockDailyBar::create([
        'symbol' => 'AGGSYM',
        'bar_date' => '2026-10-05',
        'open' => 150.0,
        'high' => 152.0,
        'low' => 148.0,
        'close' => 151.0,
        'volume' => 1000,
        'retrieved_at' => now(),
    ]);
    StockDailyBar::create([
        'symbol' => 'AGGSYM',
        'bar_date' => '2026-10-09',
        'open' => 151.0,
        'high' => 156.0,
        'low' => 150.0,
        'close' => 155.0,
        'volume' => 1500,
        'retrieved_at' => now(),
    ]);
    // Week 2 (Monday Oct 12)
    StockDailyBar::create([
        'symbol' => 'AGGSYM',
        'bar_date' => '2026-10-12',
        'open' => 155.0,
        'high' => 158.0,
        'low' => 154.0,
        'close' => 157.0,
        'volume' => 2000,
        'retrieved_at' => now(),
    ]);

    \App\Models\MarketDataPayload::create([
        'symbol' => 'AGGSYM',
        'data_type' => 'bars',
        'period' => '2026-10-05_2026-10-12',
        'payload' => [['sample' => true]],
        'retrieved_at' => now(),
    ]);

    // 1. Weekly candles
    $weekResp = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/AGGSYM/candles?interval=1week&from=2026-10-05&to=2026-10-12');

    $weekResp->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'AGGSYM',
            'interval' => '1week',
            'count' => 2,
        ]);

    $weekCandles = $weekResp->json('candles');
    expect($weekCandles)->toHaveCount(2)
        ->and($weekCandles[0]['date'])->toBe('2026-10-05')
        ->and($weekCandles[0]['open'])->toEqual(150.0)
        ->and($weekCandles[0]['high'])->toEqual(156.0)
        ->and($weekCandles[0]['low'])->toEqual(148.0)
        ->and($weekCandles[0]['close'])->toEqual(155.0)
        ->and($weekCandles[0]['volume'])->toEqual(2500)
        ->and($weekCandles[1]['date'])->toBe('2026-10-12')
        ->and($weekCandles[1]['open'])->toEqual(155.0);

    // 2. Monthly candles
    $monthResp = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/AGGSYM/candles?interval=1month&from=2026-10-05&to=2026-10-12');

    $monthResp->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'AGGSYM',
            'interval' => '1month',
            'count' => 1,
        ]);

    $monthCandles = $monthResp->json('candles');
    expect($monthCandles)->toHaveCount(1)
        ->and($monthCandles[0]['date'])->toBe('2026-10-01')
        ->and($monthCandles[0]['open'])->toEqual(150.0)
        ->and($monthCandles[0]['high'])->toEqual(158.0)
        ->and($monthCandles[0]['low'])->toEqual(148.0)
        ->and($monthCandles[0]['close'])->toEqual(157.0)
        ->and($monthCandles[0]['volume'])->toEqual(4500);
});

test('api profile endpoint returns company metadata from persistent database', function () {
    CompanyProfile::create([
        'symbol' => 'MSFT',
        'company_name' => 'Microsoft Corporation',
        'exchange' => 'NASDAQ',
        'currency' => 'USD',
        'sector' => 'Technology',
        'market_cap' => 3100000000000,
        'price' => 415.20,
        'retrieved_at' => now(),
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/MSFT/profile');

    $response->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'MSFT',
            'profile' => [
                'name' => 'Microsoft Corporation',
                'exchange' => 'NASDAQ',
                'sector' => 'Technology',
                'price' => 415.20,
            ],
        ]);
});

test('api quote endpoint returns live or stored quote', function () {
    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('quote')
        ->once()
        ->with('NVDA')
        ->andReturn([
            'symbol' => 'NVDA',
            'price' => 135.50,
            'market_cap' => 3300000000000,
        ]);

    $this->app->instance(MarketDataProvider::class, $mockProvider);

    $response = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/NVDA/quote');

    $response->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'NVDA',
            'quote' => [
                'price' => 135.50,
            ],
        ]);
});

test('api financials endpoint returns statement rows according to statement parameter', function () {
    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('quarterlyIncomeStatements')
        ->once()
        ->with('AMZN', 4)
        ->andReturn([
            ['date' => '2026-06-30', 'revenue' => 140000000000, 'eps' => 1.25],
        ]);

    $this->app->instance(MarketDataProvider::class, $mockProvider);

    $response = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/AMZN/financials?statement=income&limit=4');

    $response->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'AMZN',
            'statement' => 'income',
            'count' => 1,
        ]);
});

test('api earnings calendar and surprises return structured events', function () {
    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('earningsCalendar')
        ->once()
        ->andReturn([
            ['symbol' => 'AAPL', 'report_date' => '2026-10-25'],
        ]);
    $mockProvider->shouldReceive('earningsSurprises')
        ->once()
        ->andReturn([
            ['symbol' => 'AAPL', 'eps_surprise_percent' => 10.5],
        ]);

    $this->app->instance(MarketDataProvider::class, $mockProvider);

    $resCal = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/earnings/calendar?from=2026-10-20&to=2026-10-30');
    $resSur = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/earnings/surprises?from=2026-10-20&to=2026-10-30');

    $resCal->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'count' => 1,
        ]);

    $resSur->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'count' => 1,
        ]);
});

test('api candles endpoint rejects excessive intraday range to prevent upstream consumption', function () {
    $response = $this->withHeader('X-API-KEY', 'test-duoasset-key-12345')
        ->getJson('/api/v1/market-data/AAPL/candles?interval=15min&from=2026-01-01&to=2026-06-01');

    $response->assertStatus(422)
        ->assertJson([
            'provider' => 'duoasset',
            'error' => 'Intraday candle range cannot exceed 60 days to prevent excessive upstream consumption.',
        ]);
});

test('internal api rate limiting throttles requests when limit is exceeded', function () {
    // Configure small limit for test
    config()->set('market_data.api_rate_limit_per_minute', 3);

    for ($i = 0; $i < 3; $i++) {
        $response = $this->withHeader('X-API-KEY', 'throttle-test-key')
            ->getJson('/api/v1/market-data/AAPL/quote');
        // Either 200 or 404 (not throttled)
        expect($response->status())->not->toBe(429);
    }

    // 4th request must be throttled with HTTP 429
    $throttled = $this->withHeader('X-API-KEY', 'throttle-test-key')
        ->getJson('/api/v1/market-data/AAPL/quote');

    $throttled->assertStatus(429);
});
