<?php

use App\Models\CompanyProfile;
use App\Models\StockDailyBar;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

test('api candles endpoint returns duoasset branded payload and candles from cache or provider', function () {
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

    $response = $this->getJson('/api/v1/market-data/AAPL/candles?interval=1day&from=2026-10-01&to=2026-10-01');

    $response->assertOk()
        ->assertJson([
            'provider' => 'duoasset',
            'symbol' => 'AAPL',
            'interval' => '1day',
            'count' => 1,
        ])
        ->assertJsonFragment([
            'date' => '2026-10-01',
            'close' => 154.0,
        ]);
});

test('api candles endpoint rejects invalid interval with 422', function () {
    $response = $this->getJson('/api/v1/market-data/AAPL/candles?interval=unsupported_interval');

    $response->assertStatus(422)
        ->assertJsonFragment([
            'provider' => 'duoasset',
            'error' => 'Invalid interval parameter. Supported: 1min, 5min, 15min, 30min, 1hour, 1day, 1week, 1month.',
        ]);
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
    ]);

    $response = $this->getJson('/api/v1/market-data/MSFT/profile');

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

    $response = $this->getJson('/api/v1/market-data/NVDA/quote');

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

    $response = $this->getJson('/api/v1/market-data/AMZN/financials?statement=income&limit=4');

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

    $resCal = $this->getJson('/api/v1/market-data/earnings/calendar?from=2026-10-20&to=2026-10-30');
    $resSur = $this->getJson('/api/v1/market-data/earnings/surprises?from=2026-10-20&to=2026-10-30');

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
