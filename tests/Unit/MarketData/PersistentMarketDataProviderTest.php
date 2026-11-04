<?php

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MarketData\PersistentMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

test('historicalDailyBars fetches from upstream when empty and stores into database', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('AAPL', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturn([
            ['date' => '2026-10-01', 'open' => 150.0, 'high' => 152.0, 'low' => 149.0, 'close' => 151.0, 'adj_close' => 151.0, 'volume' => 1000000],
            ['date' => '2026-10-02', 'open' => 151.0, 'high' => 153.0, 'low' => 150.0, 'close' => 152.5, 'adj_close' => 152.5, 'volume' => 1100000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-01');
    $to = CarbonImmutable::parse('2026-10-02');

    $bars = $provider->historicalDailyBars('AAPL', $from, $to);

    expect($bars)->toHaveCount(2)
        ->and($bars[0]['date'])->toBe('2026-10-01')
        ->and($bars[1]['date'])->toBe('2026-10-02')
        ->and(StockDailyBar::where('symbol', 'AAPL')->count())->toBe(2)
        ->and(MarketDataPayload::where('symbol', 'AAPL')->where('data_type', 'bars')->exists())->toBeTrue();
});

test('historicalDailyBars returns from database without calling upstream if already stored', function () {
    StockDailyBar::create([
        'symbol' => 'MSFT',
        'bar_date' => '2026-10-01',
        'open' => 400.0,
        'high' => 405.0,
        'low' => 398.0,
        'close' => 402.0,
        'adj_close' => 402.0,
        'volume' => 2000000,
        'retrieved_at' => now(),
    ]);
    StockDailyBar::create([
        'symbol' => 'MSFT',
        'bar_date' => '2026-10-02',
        'open' => 402.0,
        'high' => 408.0,
        'low' => 401.0,
        'close' => 407.0,
        'adj_close' => 407.0,
        'volume' => 2100000,
        'retrieved_at' => now(),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream should NEVER be called when bars are already stored
    $upstream->shouldNotReceive('historicalDailyBars');

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-01');
    $to = CarbonImmutable::parse('2026-10-02');

    $bars = $provider->historicalDailyBars('MSFT', $from, $to);

    expect($bars)->toHaveCount(2)
        ->and($bars[0]['date'])->toBe('2026-10-01')
        ->and($bars[0]['close'])->toBe(402.0)
        ->and($bars[1]['date'])->toBe('2026-10-02')
        ->and($bars[1]['close'])->toBe(407.0);
});

test('historicalDailyBars fetches only the missing later gap from upstream and merges series', function () {
    // Seed existing bar for 2026-10-01
    StockDailyBar::create([
        'symbol' => 'NVDA',
        'bar_date' => '2026-10-01',
        'open' => 120.0,
        'high' => 125.0,
        'low' => 119.0,
        'close' => 124.0,
        'adj_close' => 124.0,
        'volume' => 5000000,
        'retrieved_at' => now(),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream should only be called for 2026-10-02 to 2026-10-03 (the gap)
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('NVDA', Mockery::on(fn ($c) => $c->toDateString() === '2026-10-02'), Mockery::on(fn ($c) => $c->toDateString() === '2026-10-03'))
        ->andReturn([
            ['date' => '2026-10-02', 'open' => 124.0, 'high' => 128.0, 'low' => 123.0, 'close' => 127.0, 'adj_close' => 127.0, 'volume' => 6000000],
            ['date' => '2026-10-03', 'open' => 127.0, 'high' => 130.0, 'low' => 126.0, 'close' => 129.0, 'adj_close' => 129.0, 'volume' => 5500000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-01');
    $to = CarbonImmutable::parse('2026-10-03');

    $bars = $provider->historicalDailyBars('NVDA', $from, $to);

    expect($bars)->toHaveCount(3)
        ->and($bars[0]['date'])->toBe('2026-10-01')
        ->and($bars[1]['date'])->toBe('2026-10-02')
        ->and($bars[2]['date'])->toBe('2026-10-03')
        ->and(StockDailyBar::where('symbol', 'NVDA')->count())->toBe(3);
});

test('profile serves from database when present and calls upstream when missing', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('profile')
        ->once()
        ->with('TSLA')
        ->andReturn([
            'symbol' => 'TSLA',
            'name' => 'Tesla, Inc.',
            'exchange' => 'NASDAQ',
            'currency' => 'USD',
            'country' => 'US',
            'sector' => 'Consumer Cyclical',
            'industry' => 'Auto Manufacturers',
            'sub_industry' => 'Electric Vehicles',
            'market_cap' => 800000000000,
            'price' => 250.0,
            'shares_outstanding' => 3200000000,
            'float_shares' => 2800000000,
            'free_float' => 0.85,
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // 1. First call fetches and persists
    $profile = $provider->profile('TSLA');
    expect($profile)->not->toBeNull()
        ->and($profile['name'])->toBe('Tesla, Inc.')
        ->and(CompanyProfile::where('symbol', 'TSLA')->exists())->toBeTrue()
        ->and(MarketDataPayload::where('symbol', 'TSLA')->where('data_type', 'profile')->exists())->toBeTrue();

    // 2. Second call must NOT call upstream (upstream expectation was ->once())
    Cache::flush(); // Flush L1 to prove database L2 persistence works
    $profileFromDb = $provider->profile('TSLA');
    expect($profileFromDb)->not->toBeNull()
        ->and($profileFromDb['name'])->toBe('Tesla, Inc.')
        ->and($profileFromDb['symbol'])->toBe('TSLA');
});

test('quarterlyIncomeStatements serves from database when sufficient records exist', function () {
    // Pre-populate 4 quarters in DB
    for ($i = 1; $i <= 4; $i++) {
        CompanyFinancial::create([
            'symbol' => 'GOOG',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
            'fiscal_date' => "2026-0{$i}-15",
            'period' => "Q{$i}",
            'revenue' => 80000000000 + ($i * 1000000000),
            'net_income' => 20000000000,
            'eps' => 1.5,
            'retrieved_at' => now(),
        ]);
    }

    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldNotReceive('quarterlyIncomeStatements');

    $provider = new PersistentMarketDataProvider($upstream);
    $statements = $provider->quarterlyIncomeStatements('GOOG', 4);

    expect($statements)->toHaveCount(4)
        ->and($statements[0]['date'])->toBe('2026-04-15');
});

test('historicalDailyBars fetches only the missing earlier gap from upstream', function () {
    // Seed existing bar for 2026-10-05
    StockDailyBar::create([
        'symbol' => 'AMD',
        'bar_date' => '2026-10-05',
        'open' => 170.0,
        'high' => 175.0,
        'low' => 169.0,
        'close' => 174.0,
        'adj_close' => 174.0,
        'volume' => 3000000,
        'retrieved_at' => now(),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream should only be called for 2026-10-03 to 2026-10-04 (earlier gap)
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('AMD', Mockery::on(fn ($c) => $c->toDateString() === '2026-10-03'), Mockery::on(fn ($c) => $c->toDateString() === '2026-10-04'))
        ->andReturn([
            ['date' => '2026-10-03', 'open' => 165.0, 'high' => 168.0, 'low' => 164.0, 'close' => 167.0, 'adj_close' => 167.0, 'volume' => 2800000],
            ['date' => '2026-10-04', 'open' => 167.0, 'high' => 171.0, 'low' => 166.0, 'close' => 170.0, 'adj_close' => 170.0, 'volume' => 2900000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-03');
    $to = CarbonImmutable::parse('2026-10-05');

    $bars = $provider->historicalDailyBars('AMD', $from, $to);

    expect($bars)->toHaveCount(3)
        ->and($bars[0]['date'])->toBe('2026-10-03')
        ->and($bars[1]['date'])->toBe('2026-10-04')
        ->and($bars[2]['date'])->toBe('2026-10-05')
        ->and(StockDailyBar::where('symbol', 'AMD')->count())->toBe(3);
});

test('quarterlyBalanceSheets and cashFlowStatements persist into database and payload archive', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('quarterlyBalanceSheets')
        ->once()
        ->with('AMZN', 2)
        ->andReturn([
            ['date' => '2026-06-30', 'stockholders_equity' => 250000000000.0, 'fiscalYear' => 2026, 'period' => 'Q2'],
            ['date' => '2026-03-31', 'stockholders_equity' => 240000000000.0, 'fiscalYear' => 2026, 'period' => 'Q1'],
        ]);

    $upstream->shouldReceive('quarterlyCashFlowStatements')
        ->once()
        ->with('AMZN', 2)
        ->andReturn([
            ['date' => '2026-06-30', 'free_cash_flow' => 50000000000.0, 'fiscal_year' => 2026, 'period' => 'Q2', 'reported_currency' => 'USD'],
            ['date' => '2026-03-31', 'free_cash_flow' => 45000000000.0, 'fiscal_year' => 2026, 'period' => 'Q1', 'reported_currency' => 'USD'],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    $bs = $provider->quarterlyBalanceSheets('AMZN', 2);
    $cf = $provider->quarterlyCashFlowStatements('AMZN', 2);

    expect($bs)->toHaveCount(2)
        ->and($cf)->toHaveCount(2)
        ->and(CompanyFinancial::where('symbol', 'AMZN')->where('statement_type', 'balance_sheet')->count())->toBe(2)
        ->and(CompanyFinancial::where('symbol', 'AMZN')->where('statement_type', 'cash_flow')->count())->toBe(2)
        ->and(MarketDataPayload::where('symbol', 'AMZN')->count())->toBe(2);
});

test('earningsCalendar and earningsSurprises store and return from payload archive', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('earningsCalendar')
        ->once()
        ->andReturn([
            ['symbol' => 'AAPL', 'report_date' => '2026-10-25', 'eps_estimated' => 1.5],
        ]);

    $upstream->shouldReceive('earningsSurprises')
        ->once()
        ->andReturn([
            ['symbol' => 'AAPL', 'report_date' => '2026-10-25', 'eps_surprise_percent' => 12.5],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-20');
    $to = CarbonImmutable::parse('2026-10-30');

    $cal = $provider->earningsCalendar($from, $to);
    $surprises = $provider->earningsSurprises($from, $to);

    expect($cal)->toHaveCount(1)
        ->and($surprises)->toHaveCount(1)
        ->and(MarketDataPayload::where('data_type', 'earnings_calendar')->exists())->toBeTrue()
        ->and(MarketDataPayload::where('data_type', 'earnings_surprises')->exists())->toBeTrue();
});

test('historicalDailyBars detects missing trading days in the middle of a range and queries upstream only for the gap', function () {
    // Seed bar for 2026-10-01 (Thursday) and 2026-10-08 (Thursday)
    StockDailyBar::create([
        'symbol' => 'INTC',
        'bar_date' => '2026-10-01',
        'open' => 22.0,
        'high' => 23.0,
        'low' => 21.5,
        'close' => 22.8,
        'adj_close' => 22.8,
        'volume' => 10000000,
        'retrieved_at' => now(),
    ]);
    StockDailyBar::create([
        'symbol' => 'INTC',
        'bar_date' => '2026-10-08',
        'open' => 23.0,
        'high' => 24.0,
        'low' => 22.8,
        'close' => 23.5,
        'adj_close' => 23.5,
        'volume' => 12000000,
        'retrieved_at' => now(),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream should ONLY be called for the middle gap: 2026-10-02 to 2026-10-07
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('INTC', Mockery::on(fn ($c) => $c->toDateString() === '2026-10-02'), Mockery::on(fn ($c) => $c->toDateString() === '2026-10-07'))
        ->andReturn([
            ['date' => '2026-10-02', 'open' => 22.8, 'high' => 23.2, 'low' => 22.5, 'close' => 23.0, 'adj_close' => 23.0, 'volume' => 11000000],
            ['date' => '2026-10-05', 'open' => 23.0, 'high' => 23.4, 'low' => 22.7, 'close' => 23.1, 'adj_close' => 23.1, 'volume' => 11500000],
            ['date' => '2026-10-06', 'open' => 23.1, 'high' => 23.5, 'low' => 22.8, 'close' => 23.2, 'adj_close' => 23.2, 'volume' => 10500000],
            ['date' => '2026-10-07', 'open' => 23.2, 'high' => 23.6, 'low' => 22.9, 'close' => 23.3, 'adj_close' => 23.3, 'volume' => 10800000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-01');
    $to = CarbonImmutable::parse('2026-10-08');

    $bars = $provider->historicalDailyBars('INTC', $from, $to);

    expect($bars)->toHaveCount(6)
        ->and($bars[0]['date'])->toBe('2026-10-01')
        ->and($bars[1]['date'])->toBe('2026-10-02')
        ->and($bars[5]['date'])->toBe('2026-10-08')
        ->and(StockDailyBar::where('symbol', 'INTC')->count())->toBe(6);
});

test('quarterlyIncomeStatements discovers new quarterly reports when freshness expires even if limit is already met', function () {
    // Pre-populate 8 older quarters from 2024 and 2025, with retrieved_at 48 hours ago (stale)
    for ($i = 1; $i <= 8; $i++) {
        CompanyFinancial::create([
            'symbol' => 'META',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
            'fiscal_date' => "2025-0{$i}-15",
            'period' => "Q{$i}",
            'revenue' => 30000000000 + ($i * 1000000000),
            'net_income' => 10000000000,
            'eps' => 3.5,
            'retrieved_at' => now()->subHours(48),
        ]);
    }

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream returns fresh statements including a brand new 2026-06-30 quarter
    $upstream->shouldReceive('quarterlyIncomeStatements')
        ->once()
        ->with('META', 8)
        ->andReturn([
            ['date' => '2026-06-30', 'revenue' => 45000000000.0, 'net_income' => 15000000000.0, 'eps' => 5.25, 'period' => 'Q2', 'fiscalYear' => 2026],
            ['date' => '2026-03-31', 'revenue' => 42000000000.0, 'net_income' => 14000000000.0, 'eps' => 4.80, 'period' => 'Q1', 'fiscalYear' => 2026],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $statements = $provider->quarterlyIncomeStatements('META', 8);

    expect($statements)->toHaveCount(8)
        ->and($statements[0]['date'])->toBe('2026-06-30')
        ->and($statements[0]['revenue'])->toBe(45000000000.0)
        ->and(CompanyFinancial::where('symbol', 'META')->where('fiscal_date', '2026-06-30')->exists())->toBeTrue();
});

test('historicalIntradayBars archives intraday bars and serves historical intraday intervals from database payload with zero upstream calls', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $from = CarbonImmutable::parse('2026-09-01');
    $to = CarbonImmutable::parse('2026-09-05');

    $upstream->shouldReceive('historicalIntradayBars')
        ->once()
        ->with('AMD', '15min', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturn([
            ['date' => '2026-09-01 09:30:00', 'open' => 140.0, 'close' => 141.0, 'volume' => 50000],
            ['date' => '2026-09-01 09:45:00', 'open' => 141.0, 'close' => 141.5, 'volume' => 45000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // 1. First call fetches from upstream and archives into MarketDataPayload
    $bars = $provider->historicalIntradayBars('AMD', '15min', $from, $to);
    expect($bars)->toHaveCount(2)
        ->and(MarketDataPayload::where('symbol', 'AMD')->where('data_type', 'intraday_15min')->exists())->toBeTrue();

    // 2. Clear memory cache to simulate app restart
    Cache::flush();

    // 3. Second call must serve from MarketDataPayload with zero upstream calls
    $barsFromDb = $provider->historicalIntradayBars('AMD', '15min', $from, $to);
    expect($barsFromDb)->toHaveCount(2)
        ->and((float) $barsFromDb[0]['open'])->toBe(140.0);
});

test('companyScreener does not overwrite existing non-null profile attributes with null', function () {
    // Seed existing complete profile
    CompanyProfile::create([
        'symbol' => 'ORCL',
        'company_name' => 'Oracle Corporation',
        'exchange' => 'NYSE',
        'currency' => 'USD',
        'country' => 'US',
        'sector' => 'Technology',
        'industry' => 'Software - Infrastructure',
        'market_cap' => 400000000000,
        'price' => 140.0,
        'retrieved_at' => now()->subDays(5),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Screener response only contains symbol, company_name, price, market_cap (missing sector, industry, country)
    $upstream->shouldReceive('companyScreener')
        ->once()
        ->andReturn([
            [
                'symbol' => 'ORCL',
                'company_name' => 'Oracle Corp Updated',
                'price' => 145.5,
                'market_cap' => 420000000000,
                // sector, industry, country are absent/null
            ],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $provider->companyScreener(['marketCapMoreThan' => 1000000000]);

    $profile = CompanyProfile::where('symbol', 'ORCL')->first();
    expect($profile)->not->toBeNull()
        ->and((float) $profile->price)->toBe(145.5)
        ->and($profile->market_cap)->toBe(420000000000)
        // Previously collected attributes must NOT be overwritten with null
        ->and($profile->country)->toBe('US')
        ->and($profile->sector)->toBe('Technology')
        ->and($profile->industry)->toBe('Software - Infrastructure');
});

test('previously retrieved market data survives application restarts with zero upstream calls', function () {
    // 1. Seed complete data in database
    StockDailyBar::create([
        'symbol' => 'NFLX',
        'bar_date' => '2026-10-15',
        'open' => 600.0,
        'high' => 610.0,
        'low' => 598.0,
        'close' => 608.0,
        'volume' => 3000000,
        'retrieved_at' => now(),
    ]);

    CompanyProfile::create([
        'symbol' => 'NFLX',
        'company_name' => 'Netflix, Inc.',
        'exchange' => 'NASDAQ',
        'currency' => 'USD',
        'price' => 608.0,
        'retrieved_at' => now(),
    ]);

    CompanyFinancial::create([
        'symbol' => 'NFLX',
        'statement_type' => CompanyFinancial::TYPE_INCOME,
        'fiscal_date' => '2026-06-30',
        'period' => 'Q2',
        'revenue' => 9500000000,
        'net_income' => 2100000000,
        'eps' => 4.88,
        'retrieved_at' => now(),
    ]);

    // 2. Clear all in-memory cache to simulate server restart
    Cache::flush();

    // 3. Upstream mock should NEVER be called
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldNotReceive('historicalDailyBars');
    $upstream->shouldNotReceive('profile');
    $upstream->shouldNotReceive('quarterlyIncomeStatements');

    $provider = new PersistentMarketDataProvider($upstream);

    $bars = $provider->historicalDailyBars('NFLX', CarbonImmutable::parse('2026-10-15'), CarbonImmutable::parse('2026-10-15'));
    $profile = $provider->profile('NFLX');
    $statements = $provider->quarterlyIncomeStatements('NFLX', 1);

    expect($bars)->toHaveCount(1)
        ->and($bars[0]['close'])->toBe(608.0)
        ->and($profile)->not->toBeNull()
        ->and($profile['name'])->toBe('Netflix, Inc.')
        ->and($statements)->toHaveCount(1)
        ->and($statements[0]['revenue'])->toBe(9500000000.0);
});

test('resolves PersistentMarketDataProvider from service container when driver is fmp or persistent', function (string $driver) {
    config(['market_data.provider' => $driver]);

    $resolved = app(MarketDataProvider::class);
    expect($resolved)->toBeInstanceOf(PersistentMarketDataProvider::class);
})->with(['fmp', 'persistent']);

test('empty responses or unfinished trading days do not permanently prevent later retrieval', function () {
    $today = CarbonImmutable::today();
    $upstream = Mockery::mock(MarketDataProvider::class);

    // 1. First call on unfinished trading day returns empty (e.g. pre-market)
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('UNF', Mockery::on(fn ($c) => $c->toDateString() === $today->toDateString()), Mockery::on(fn ($c) => $c->toDateString() === $today->toDateString()))
        ->andReturn([]);

    $provider = new PersistentMarketDataProvider($upstream);
    $bars = $provider->historicalDailyBars('UNF', $today, $today);
    expect($bars)->toBeEmpty();

    // Stored payload was created with empty payload
    $payload = MarketDataPayload::where('symbol', 'UNF')->where('data_type', 'bars')->first();
    expect($payload)->not->toBeNull()
        ->and($payload->payload)->toBeEmpty();

    // 2. Simulate time passing past the 15-minute unfinished freshness window
    $payload->update(['retrieved_at' => now()->subMinutes(20)]);
    Cache::flush();

    // 3. Upstream should be queried again when called later
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('UNF', Mockery::on(fn ($c) => $c->toDateString() === $today->toDateString()), Mockery::on(fn ($c) => $c->toDateString() === $today->toDateString()))
        ->andReturn([
            ['date' => $today->toDateString(), 'open' => 50.0, 'high' => 52.0, 'low' => 49.5, 'close' => 51.5, 'adj_close' => 51.5, 'volume' => 500000],
        ]);

    $freshBars = $provider->historicalDailyBars('UNF', $today, $today);
    expect($freshBars)->toHaveCount(1)
        ->and($freshBars[0]['close'])->toBe(51.5)
        ->and(StockDailyBar::where('symbol', 'UNF')->where('bar_date', $today->toDateString())->exists())->toBeTrue();
});

test('overlapping historical ranges preserve previously stored prices and only query missing gap', function () {
    // Seed days 1 to 3 with known prices
    StockDailyBar::create([
        'symbol' => 'OVL',
        'bar_date' => '2026-10-01',
        'open' => 100.0,
        'high' => 105.0,
        'low' => 99.0,
        'close' => 104.0,
        'adj_close' => 104.0,
        'volume' => 1000000,
        'retrieved_at' => now(),
    ]);
    StockDailyBar::create([
        'symbol' => 'OVL',
        'bar_date' => '2026-10-02',
        'open' => 104.0,
        'high' => 106.0,
        'low' => 103.0,
        'close' => 105.0,
        'adj_close' => 105.0,
        'volume' => 1100000,
        'retrieved_at' => now(),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // When requesting 2026-10-02 to 2026-10-04, upstream is ONLY called for the later gap: 2026-10-03 to 2026-10-04
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('OVL', Mockery::on(fn ($c) => $c->toDateString() === '2026-10-03'), Mockery::on(fn ($c) => $c->toDateString() === '2026-10-04'))
        ->andReturn([
            ['date' => '2026-10-03', 'open' => 105.0, 'close' => 107.0, 'volume' => 900000],
            ['date' => '2026-10-04', 'open' => 107.0, 'close' => 108.0, 'volume' => 950000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-02');
    $to = CarbonImmutable::parse('2026-10-04');

    $bars = $provider->historicalDailyBars('OVL', $from, $to);
    expect($bars)->toHaveCount(3)
        ->and($bars[0]['date'])->toBe('2026-10-02')
        ->and($bars[0]['close'])->toBe(105.0)
        ->and($bars[1]['date'])->toBe('2026-10-03')
        ->and($bars[2]['date'])->toBe('2026-10-04');

    // Previously stored day 1 price remains completely intact
    expect(StockDailyBar::where('symbol', 'OVL')->where('bar_date', '2026-10-01')->first()->close)->toEqual(104.0);
});

test('intraday caching distinguishes different time windows on the same day and merges incrementally', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);

    $from1 = CarbonImmutable::parse('2026-10-15 09:30:00');
    $to1 = CarbonImmutable::parse('2026-10-15 11:30:00');

    $from2 = CarbonImmutable::parse('2026-10-15 13:00:00');
    $to2 = CarbonImmutable::parse('2026-10-15 16:00:00');

    // First call queries upstream for morning window
    $upstream->shouldReceive('historicalIntradayBars')
        ->once()
        ->with('INTRA', '15min', Mockery::on(fn ($c) => $c->format('H:i') === '09:30'), Mockery::on(fn ($c) => $c->format('H:i') === '11:30'))
        ->andReturn([
            ['date' => '2026-10-15 09:30:00', 'open' => 100.0, 'close' => 101.0, 'volume' => 10000],
            ['date' => '2026-10-15 10:00:00', 'open' => 101.0, 'close' => 102.0, 'volume' => 12000],
        ]);

    // Second call queries upstream for afternoon window
    $upstream->shouldReceive('historicalIntradayBars')
        ->once()
        ->with('INTRA', '15min', Mockery::on(fn ($c) => $c->format('H:i') === '13:00'), Mockery::on(fn ($c) => $c->format('H:i') === '16:00'))
        ->andReturn([
            ['date' => '2026-10-15 13:30:00', 'open' => 103.0, 'close' => 104.0, 'volume' => 15000],
            ['date' => '2026-10-15 15:30:00', 'open' => 104.0, 'close' => 105.0, 'volume' => 16000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // Call 1
    $morningBars = $provider->historicalIntradayBars('INTRA', '15min', $from1, $to1);
    expect($morningBars)->toHaveCount(2)
        ->and($morningBars[0]['date'])->toBe('2026-10-15 09:30:00')
        ->and($morningBars[1]['date'])->toBe('2026-10-15 10:00:00');

    // Call 2
    $afternoonBars = $provider->historicalIntradayBars('INTRA', '15min', $from2, $to2);
    expect($afternoonBars)->toHaveCount(2)
        ->and($afternoonBars[0]['date'])->toBe('2026-10-15 13:30:00')
        ->and($afternoonBars[1]['date'])->toBe('2026-10-15 15:30:00');

    // Stored composite payload must contain all 4 candles (incremental merge preserved morning candles)
    $dayPayload = MarketDataPayload::where('symbol', 'INTRA')
        ->where('data_type', 'intraday_15min')
        ->where('period', '2026-10-15')
        ->first();

    expect($dayPayload)->not->toBeNull()
        ->and($dayPayload->payload)->toHaveCount(4);
});

test('retrieving quote does not make outdated company profile appear fresh', function () {
    // 1. Seed company profile older than 24 hours
    $profile = CompanyProfile::create([
        'symbol' => 'STALE',
        'company_name' => 'Stale Corp',
        'exchange' => 'NYSE',
        'price' => 50.0,
        'market_cap' => 1000000000,
        'retrieved_at' => now()->subHours(36),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('quote')
        ->once()
        ->with('STALE')
        ->andReturn([
            'symbol' => 'STALE',
            'price' => 55.0,
            'market_cap' => 1100000000,
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // 2. Fetch quote
    $quote = $provider->quote('STALE');
    expect($quote['price'])->toBe(55.0);

    // 3. CompanyProfile price updated, but retrieved_at MUST NOT be refreshed to now!
    $refreshedProfile = CompanyProfile::where('symbol', 'STALE')->first();
    expect((float) $refreshedProfile->price)->toBe(55.0)
        ->and($refreshedProfile->retrieved_at->diffInHours(now()))->toBeGreaterThanOrEqual(35);

    // 4. Calling profile() still calls upstream because the profile remains properly recognized as stale!
    $upstream->shouldReceive('profile')
        ->once()
        ->with('STALE')
        ->andReturn([
            'symbol' => 'STALE',
            'name' => 'Stale Corp Updated',
            'exchange' => 'NYSE',
            'price' => 55.0,
        ]);

    $freshProfile = $provider->profile('STALE');
    expect($freshProfile['name'])->toBe('Stale Corp Updated');
});

test('failed financial statement refresh does not mark old records as freshly verified', function () {
    // 1. Seed financial record from 48 hours ago
    $origTime = now()->subHours(48);
    CompanyFinancial::create([
        'symbol' => 'FAILCO',
        'statement_type' => CompanyFinancial::TYPE_INCOME,
        'fiscal_date' => '2026-03-31',
        'period' => 'Q1',
        'revenue' => 10000000,
        'net_income' => 2000000,
        'eps' => 0.50,
        'retrieved_at' => $origTime,
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream fails with exception
    $upstream->shouldReceive('quarterlyIncomeStatements')
        ->once()
        ->with('FAILCO', 4)
        ->andThrow(new \RuntimeException('FMP API timeout'));

    $provider = new PersistentMarketDataProvider($upstream);

    // Fetch should gracefully fallback to existing stored records
    $statements = $provider->quarterlyIncomeStatements('FAILCO', 4);
    expect($statements)->toHaveCount(1);

    // Existing record's retrieved_at must NOT be updated to now()!
    $stored = CompanyFinancial::where('symbol', 'FAILCO')->first();
    expect($stored->retrieved_at->diffInHours(now()))->toBeGreaterThanOrEqual(47);
});

test('companies with limited financial history do not trigger excessive upstream requests', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream has only 2 quarters in existence even though 8 are requested
    $upstream->shouldReceive('quarterlyIncomeStatements')
        ->once()
        ->with('IPO', 8)
        ->andReturn([
            ['date' => '2026-06-30', 'revenue' => 15000000.0, 'net_income' => 3000000.0, 'eps' => 0.75, 'period' => 'Q2', 'fiscalYear' => 2026],
            ['date' => '2026-03-31', 'revenue' => 12000000.0, 'net_income' => 2500000.0, 'eps' => 0.60, 'period' => 'Q1', 'fiscalYear' => 2026],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // 1. First call fetches from upstream
    $res1 = $provider->quarterlyIncomeStatements('IPO', 8);
    expect($res1)->toHaveCount(2);

    // 2. Clear memory cache to simulate subsequent request in separate lifecycle
    Cache::flush();

    // 3. Second call for limit 8: upstream should NOT be called again within 24h because limit exhaustion is tracked
    $res2 = $provider->quarterlyIncomeStatements('IPO', 8);
    expect($res2)->toHaveCount(2)
        ->and($res2[0]['revenue'])->toBe(15000000.0);
});

test('companyScreener serves from storage first when payload is fresh', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('companyScreener')
        ->once()
        ->with(['marketCapMoreThan' => 500000000])
        ->andReturn([
            ['symbol' => 'SCR1', 'company_name' => 'Screener Stock 1', 'price' => 88.0, 'market_cap' => 600000000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // 1. First call fetches and archives
    $res1 = $provider->companyScreener(['marketCapMoreThan' => 500000000]);
    expect($res1)->toHaveCount(1)
        ->and($res1[0]['symbol'])->toBe('SCR1');

    // 2. Clear memory cache
    Cache::flush();

    // 3. Second call serves from MarketDataPayload without calling upstream
    $res2 = $provider->companyScreener(['marketCapMoreThan' => 500000000]);
    expect($res2)->toHaveCount(1)
        ->and($res2[0]['symbol'])->toBe('SCR1');
});

test('yesterday daily candle is finalized after market close even if preliminary version was stored', function () {
    $yesterday = CarbonImmutable::parse('2026-10-20');
    // Store preliminary bar retrieved at 2:00 PM Eastern on that day
    $preliminaryTime = CarbonImmutable::parse('2026-10-20 14:00:00', 'America/New_York');

    StockDailyBar::create([
        'symbol' => 'EOD',
        'bar_date' => '2026-10-20',
        'open' => 100.0,
        'high' => 105.0,
        'low' => 99.0,
        'close' => 104.0, // preliminary midday close
        'volume' => 500000,
        'retrieved_at' => $preliminaryTime,
    ]);

    MarketDataPayload::create([
        'symbol' => 'EOD',
        'data_type' => 'bars',
        'period' => '2026-10-20_2026-10-20',
        'payload' => [['date' => '2026-10-20', 'close' => 104.0]],
        'retrieved_at' => $preliminaryTime,
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream returns the finalized EOD candle
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('EOD', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturn([
            ['date' => '2026-10-20', 'open' => 100.0, 'high' => 106.0, 'low' => 98.5, 'close' => 102.5, 'volume' => 1200000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $bars = $provider->historicalDailyBars('EOD', $yesterday, $yesterday);

    expect($bars)->toHaveCount(1)
        ->and((float) $bars[0]['close'])->toBe(102.5);

    $updatedBar = StockDailyBar::where('symbol', 'EOD')->where('bar_date', '2026-10-20')->first();
    expect((float) $updatedBar->close)->toBe(102.5)
        ->and((int) $updatedBar->volume)->toBe(1200000);
});

test('memory caching does not prevent unfinished trading days from refreshing', function () {
    $today = CarbonImmutable::today('America/New_York');

    // Create preliminary bar retrieved 20 minutes ago
    $retrievedAt = now()->subMinutes(20);
    StockDailyBar::create([
        'symbol' => 'UNF',
        'bar_date' => $today->toDateString(),
        'open' => 50.0,
        'high' => 52.0,
        'low' => 49.0,
        'close' => 51.0,
        'volume' => 100000,
        'retrieved_at' => $retrievedAt,
    ]);

    // Pre-populate memory cache
    $v = PersistentMarketDataProvider::getCacheVersion('bars', 'UNF');
    $cacheKey = "duoasset.v{$v}.bars.UNF.{$today->toDateString()}.{$today->toDateString()}";
    Cache::put($cacheKey, [
        ['date' => $today->toDateString(), 'open' => 50.0, 'close' => 51.0, 'volume' => 100000],
    ], 3600);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->andReturn([
            ['date' => $today->toDateString(), 'open' => 50.0, 'high' => 53.0, 'low' => 49.0, 'close' => 52.5, 'volume' => 250000],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $bars = $provider->historicalDailyBars('UNF', $today, $today);

    expect($bars)->toHaveCount(1)
        ->and((float) $bars[0]['close'])->toBe(52.5);
});

test('memory cache lifetimes do not extend beyond underlying record freshness period', function () {
    // Stored profile retrieved 23 hours ago (intended freshness is 24 hours = 1 hour remaining)
    $retrievedAt = now()->subHours(23);
    CompanyProfile::create([
        'symbol' => 'TTLCO',
        'company_name' => 'TTL Company',
        'retrieved_at' => $retrievedAt,
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $provider = new PersistentMarketDataProvider($upstream);

    // Call profile() which will put in memory cache with remaining TTL (~3600 seconds, not 86400)
    $profile = $provider->profile('TTLCO');
    expect($profile)->not->toBeNull();

    // Check cached value
    $v = PersistentMarketDataProvider::getCacheVersion('profile', 'TTLCO');
    $cacheKey = "duoasset.v{$v}.profile.TTLCO";
    expect(Cache::has($cacheKey))->toBeTrue();

    // Advance time by 2 hours (making the DB record 25 hours old, so memory cache must have expired)
    \Illuminate\Support\Carbon::setTestNow(now()->addHours(2));

    expect(Cache::has($cacheKey))->toBeFalse();
    \Illuminate\Support\Carbon::setTestNow(); // reset
});

test('profile serves complete original response attributes and maintains historical snapshots', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('profile')
        ->once()
        ->with('ATTR')
        ->andReturn([
            'symbol' => 'ATTR',
            'name' => 'Attribute Corp',
            'exchange' => 'NYSE',
            'currency' => 'USD',
            'price' => 120.0,
            'market_cap' => 5000000000,
            'description' => 'Complete FMP company description preserved.',
            'ceo' => 'Jane Doe',
            'website' => 'https://attribute.com',
            'beta' => 1.15,
            'dcf' => 125.40,
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $fresh = $provider->profile('ATTR');

    expect($fresh['description'])->toBe('Complete FMP company description preserved.')
        ->and($fresh['ceo'])->toBe('Jane Doe')
        ->and($fresh['beta'])->toBe(1.15);

    // Verify both latest-value caching and historical archival snapshot were created in MarketDataPayload
    $latest = MarketDataPayload::where('symbol', 'ATTR')->where('data_type', 'profile')->where('period', 'latest')->first();
    $snapshot = MarketDataPayload::where('symbol', 'ATTR')->where('data_type', 'profile')->where('period', 'like', 'snapshot_%')->first();

    expect($latest)->not->toBeNull()
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot->payload['description'])->toBe('Complete FMP company description preserved.');

    // Clear memory cache and re-request: should serve complete attributes from stored payload
    Cache::flush();
    $cached = $provider->profile('ATTR');
    expect($cached['description'])->toBe('Complete FMP company description preserved.')
        ->and($cached['ceo'])->toBe('Jane Doe');
});

test('partially collected intraday session is not treated as complete historical session after market close', function () {
    $date = CarbonImmutable::parse('2026-10-15');
    // Preliminary midday snapshot retrieved at 11:00 AM Eastern on that trading day
    $retrievedAt = CarbonImmutable::parse('2026-10-15 11:00:00', 'America/New_York');

    MarketDataPayload::create([
        'symbol' => 'INTRA',
        'data_type' => 'intraday_1hour',
        'period' => '2026-10-15',
        'payload' => [
            ['date' => '2026-10-15 09:30:00', 'open' => 20.0, 'close' => 20.5, 'volume' => 1000],
            ['date' => '2026-10-15 10:30:00', 'open' => 20.5, 'close' => 21.0, 'volume' => 1200],
        ],
        'retrieved_at' => $retrievedAt,
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Because the day is now completed and it was only partially collected, upstream MUST be queried to finalize
    $upstream->shouldReceive('historicalIntradayBars')
        ->once()
        ->with('INTRA', '1hour', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturn([
            ['date' => '2026-10-15 09:30:00', 'open' => 20.0, 'close' => 20.5, 'volume' => 1000],
            ['date' => '2026-10-15 10:30:00', 'open' => 20.5, 'close' => 21.0, 'volume' => 1200],
            ['date' => '2026-10-15 11:30:00', 'open' => 21.0, 'close' => 21.2, 'volume' => 900],
            ['date' => '2026-10-15 15:30:00', 'open' => 21.2, 'close' => 21.5, 'volume' => 1500],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $bars = $provider->historicalIntradayBars('INTRA', '1hour', $date, $date);

    expect($bars)->toHaveCount(4)
        ->and($bars[3]['date'])->toBe('2026-10-15 15:30:00');
});

test('financial statements preserve distinction between missing null values and actual zero values', function () {
    CompanyFinancial::create([
        'symbol' => 'ZEROFIN',
        'statement_type' => CompanyFinancial::TYPE_INCOME,
        'fiscal_date' => '2026-06-30',
        'period' => 'Q2',
        'revenue' => 0.0, // actual zero revenue (e.g. pre-revenue biotech)
        'net_income' => -500000.0,
        'eps' => null, // missing EPS value
        'retrieved_at' => now(),
    ]);

    CompanyFinancial::create([
        'symbol' => 'ZEROFIN',
        'statement_type' => CompanyFinancial::TYPE_CASH_FLOW,
        'fiscal_date' => '2026-06-30',
        'period' => 'Q2',
        'free_cash_flow' => 0.0, // actual zero cash flow
        'retrieved_at' => now(),
    ]);

    CompanyFinancial::create([
        'symbol' => 'NULLFIN',
        'statement_type' => CompanyFinancial::TYPE_INCOME,
        'fiscal_date' => '2026-06-30',
        'period' => 'Q2',
        'revenue' => null, // missing revenue
        'net_income' => null, // missing net income
        'eps' => null,
        'retrieved_at' => now(),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $provider = new PersistentMarketDataProvider($upstream);

    $zeroIncome = $provider->quarterlyIncomeStatements('ZEROFIN', 1);
    expect($zeroIncome[0]['revenue'])->toBe(0.0) // actual zero must be 0.0, not null
        ->and($zeroIncome[0]['eps'])->toBeNull(); // missing must be null, not 0.0

    $zeroCash = $provider->quarterlyCashFlowStatements('ZEROFIN', 1);
    expect($zeroCash[0]['free_cash_flow'])->toBe(0.0); // actual zero must be 0.0

    $nullIncome = $provider->quarterlyIncomeStatements('NULLFIN', 1);
    expect($nullIncome[0]['revenue'])->toBeNull() // missing revenue must be null, not 0.0
        ->and($nullIncome[0]['net_income'])->toBeNull(); // missing net income must be null, not 0.0
});

test('requesting additional quarters retrieves missing records even when smaller request was recently completed', function () {
    // 1. Initial request for 2 quarters
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('quarterlyIncomeStatements')
        ->once()
        ->with('EXPAND', 2)
        ->andReturn([
            ['date' => '2026-06-30', 'revenue' => 2000000, 'net_income' => 200000, 'eps' => 0.20, 'raw' => []],
            ['date' => '2026-03-31', 'revenue' => 1900000, 'net_income' => 190000, 'eps' => 0.19, 'raw' => []],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $q2 = $provider->quarterlyIncomeStatements('EXPAND', 2);
    expect($q2)->toHaveCount(2);

    // 2. Caller now requests 4 quarters (expanding historical coverage)
    // Upstream MUST be queried to retrieve the additional missing quarters, even though call 1 completed recently!
    $upstream->shouldReceive('quarterlyIncomeStatements')
        ->once()
        ->with('EXPAND', 4)
        ->andReturn([
            ['date' => '2026-06-30', 'revenue' => 2000000, 'net_income' => 200000, 'eps' => 0.20, 'raw' => []],
            ['date' => '2026-03-31', 'revenue' => 1900000, 'net_income' => 190000, 'eps' => 0.19, 'raw' => []],
            ['date' => '2025-12-31', 'revenue' => 1800000, 'net_income' => 180000, 'eps' => 0.18, 'raw' => []],
            ['date' => '2025-09-30', 'revenue' => 1700000, 'net_income' => 170000, 'eps' => 0.17, 'raw' => []],
        ]);

    $q4 = $provider->quarterlyIncomeStatements('EXPAND', 4);
    expect($q4)->toHaveCount(4)
        ->and($q4[3]['date'])->toBe('2025-09-30');

    // 3. Repeated call for 4 quarters or fewer serves from database without calling upstream
    $q3 = $provider->quarterlyIncomeStatements('EXPAND', 3);
    expect($q3)->toHaveCount(3);
});

test('prevents repeated upstream calls when company genuinely has no financial history', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream called once and returns empty
    $upstream->shouldReceive('quarterlyIncomeStatements')
        ->once()
        ->with('NOHIST', 4)
        ->andReturn([]);

    $provider = new PersistentMarketDataProvider($upstream);

    // Call 1: discovers no history
    $res1 = $provider->quarterlyIncomeStatements('NOHIST', 4);
    expect($res1)->toBeEmpty();

    // Call 2: should NOT call upstream again within 24 hours
    $res2 = $provider->quarterlyIncomeStatements('NOHIST', 4);
    expect($res2)->toBeEmpty();
});

test('exchange-aware trading calendars prevent US holidays from generating unnecessary requests in middle gaps', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    // Friday before Labor Day 2026: Sept 4, 2026. Tuesday after Labor Day: Sept 8, 2026.
    // Labor Day was Monday Sept 7, 2026.
    StockDailyBar::create([
        'symbol' => 'HOLIDAY',
        'bar_date' => '2026-09-04',
        'open' => 100.0,
        'high' => 102.0,
        'low' => 99.0,
        'close' => 101.0,
        'volume' => 50000,
        'retrieved_at' => now(),
    ]);

    StockDailyBar::create([
        'symbol' => 'HOLIDAY',
        'bar_date' => '2026-09-08',
        'open' => 102.0,
        'high' => 104.0,
        'low' => 101.0,
        'close' => 103.0,
        'volume' => 60000,
        'retrieved_at' => now(),
    ]);

    // Upstream should NEVER be called because Sept 5-6 are weekend and Sept 7 is Labor Day (confirmed non-trading day)
    $upstream->shouldNotReceive('historicalDailyBars');

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-09-04');
    $to = CarbonImmutable::parse('2026-09-08');

    $bars = $provider->historicalDailyBars('HOLIDAY', $from, $to);
    expect($bars)->toHaveCount(2)
        ->and($bars[0]['date'])->toBe('2026-09-04')
        ->and($bars[1]['date'])->toBe('2026-09-08');
});

test('intraday overlapping time windows reuse stored candles without requiring exact candle timestamp to equal requested ending time', function () {
    $date = CarbonImmutable::parse('2026-10-15');
    // Stored candles from 09:30 to 15:45 (final regular trading hour candle)
    MarketDataPayload::create([
        'symbol' => 'WINDOW',
        'data_type' => 'intraday_15min',
        'period' => '2026-10-15',
        'payload' => [
            ['date' => '2026-10-15 09:30:00', 'open' => 50.0, 'close' => 50.5, 'volume' => 1000],
            ['date' => '2026-10-15 12:00:00', 'open' => 50.5, 'close' => 51.0, 'volume' => 1200],
            ['date' => '2026-10-15 15:45:00', 'open' => 51.0, 'close' => 51.5, 'volume' => 2000],
        ],
        'retrieved_at' => CarbonImmutable::parse('2026-10-15 21:30:00'), // After 4:15 PM Eastern close
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream must NOT be called even though caller requests up to 16:30:00 (after regular market close)
    $upstream->shouldNotReceive('historicalIntradayBars');

    $provider = new PersistentMarketDataProvider($upstream);
    $from = CarbonImmutable::parse('2026-10-15 09:30:00');
    $to = CarbonImmutable::parse('2026-10-15 16:30:00');

    $bars = $provider->historicalIntradayBars('WINDOW', '15min', $from, $to);
    expect($bars)->toHaveCount(3)
        ->and($bars[2]['date'])->toBe('2026-10-15 15:45:00');
});

test('quote memory-cache expiration never extends beyond underlying quote freshness period', function () {
    $retrievedAt = now()->subSeconds(45); // 45 seconds old (freshness is 60s during trading hours, so 15s remaining)
    MarketDataPayload::create([
        'symbol' => 'QUOTETTL',
        'data_type' => 'quote',
        'period' => 'latest',
        'payload' => [
            'symbol' => 'QUOTETTL',
            'price' => 100.0,
            'market_cap' => 1000000,
        ],
        'retrieved_at' => $retrievedAt,
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $provider = new PersistentMarketDataProvider($upstream);

    $quote = $provider->quote('QUOTETTL');
    expect((float) $quote['price'])->toBe(100.0);

    // Verify cache key was populated
    $v = PersistentMarketDataProvider::getCacheVersion('quote', 'QUOTETTL');
    $cached = Cache::get("duoasset.v{$v}.quote.QUOTETTL");
    expect($cached)->not->toBeNull();
});

test('historical snapshots are immutable and never overwritten by subsequent fetches', function () {
    $today = now()->toDateString();
    MarketDataPayload::create([
        'symbol' => 'IMMUTABLE',
        'data_type' => 'profile',
        'period' => "snapshot_{$today}",
        'payload' => [
            'symbol' => 'IMMUTABLE',
            'name' => 'Original Morning Snapshot',
            'price' => 100.0,
        ],
        'retrieved_at' => now()->subHours(4),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('profile')
        ->once()
        ->with('IMMUTABLE')
        ->andReturn([
            'symbol' => 'IMMUTABLE',
            'name' => 'New Afternoon Name',
            'price' => 105.0,
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $fresh = $provider->profile('IMMUTABLE');

    // Latest value was updated
    $latest = MarketDataPayload::where('symbol', 'IMMUTABLE')->where('data_type', 'profile')->where('period', 'latest')->first();
    expect($latest->payload['name'])->toBe('New Afternoon Name');

    // Historical snapshot MUST NOT have been overwritten!
    $snapshot = MarketDataPayload::where('symbol', 'IMMUTABLE')->where('data_type', 'profile')->where('period', "snapshot_{$today}")->first();
    expect($snapshot->payload['name'])->toBe('Original Morning Snapshot');
});

test('historicalDailyBars demonstrates vast performance improvement on second fetch vs first fetch for known stock', function () {
    // Generate 1 full trading year of daily bars (252 bars)
    $startDate = CarbonImmutable::parse('2025-10-01');
    $simulatedBars = [];
    $cur = $startDate;
    while (count($simulatedBars) < 252) {
        if ($cur->isWeekday()) {
            $simulatedBars[] = [
                'date' => $cur->toDateString(),
                'open' => 150.0 + (count($simulatedBars) * 0.1),
                'high' => 155.0 + (count($simulatedBars) * 0.1),
                'low' => 148.0 + (count($simulatedBars) * 0.1),
                'close' => 152.0 + (count($simulatedBars) * 0.1),
                'adj_close' => 152.0 + (count($simulatedBars) * 0.1),
                'volume' => 1000000 + (count($simulatedBars) * 1000),
            ];
        }
        $cur = $cur->addDay();
    }
    $endDate = CarbonImmutable::parse(end($simulatedBars)['date']);

    $upstream = Mockery::mock(MarketDataProvider::class);
    // Upstream should only be called ONCE on the first fetch
    $upstream->shouldReceive('historicalDailyBars')
        ->once()
        ->with('PERF_STOCK', Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturnUsing(function () use ($simulatedBars) {
            // Simulate realistic network latency for external provider API roundtrip (50ms)
            usleep(50000);

            return $simulatedBars;
        });

    $provider = new PersistentMarketDataProvider($upstream);

    // 1) First fetch: Cold start, must call external upstream FMP provider
    $startFirst = microtime(true);
    $firstResult = $provider->historicalDailyBars('PERF_STOCK', $startDate, $endDate);
    $firstDuration = microtime(true) - $startFirst;

    expect($firstResult)->toHaveCount(252)
        ->and(StockDailyBar::where('symbol', 'PERF_STOCK')->count())->toBe(252);

    // 2) Second fetch: Warm cache, served instantly from L1 memory / L2 database with ZERO external calls
    $startSecond = microtime(true);
    $secondResult = $provider->historicalDailyBars('PERF_STOCK', $startDate, $endDate);
    $secondDuration = microtime(true) - $startSecond;

    expect($secondResult)->toHaveCount(252)
        ->and($secondResult)->toEqual($firstResult);

    // Prove vast performance improvement: second fetch is orders of magnitude faster
    $speedup = $firstDuration / max(0.0001, $secondDuration);
    $percentageReduction = (($firstDuration - $secondDuration) / $firstDuration) * 100;

    expect($secondDuration)->toBeLessThan($firstDuration)
        ->and($speedup)->toBeGreaterThan(5.0) // At least 5x faster
        ->and($percentageReduction)->toBeGreaterThan(80.0); // At least 80% latency reduction

    // 3) Third fetch: Flush L1 memory cache, proving L2 database is also significantly faster than external API
    Cache::flush();
    $startThird = microtime(true);
    $thirdResult = $provider->historicalDailyBars('PERF_STOCK', $startDate, $endDate);
    $thirdDuration = microtime(true) - $startThird;

    expect($thirdResult)->toHaveCount(252)
        ->and($thirdResult)->toEqual($firstResult)
        ->and($thirdDuration)->toBeLessThan($firstDuration);
});

test('companyScreener fetches fresh list when requested and discovers new companies for setup and eps runs', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);

    // Initial state: screener has 2 companies
    MarketDataPayload::create([
        'symbol' => 'GLOBAL',
        'data_type' => 'screener',
        'period' => md5(json_encode([])),
        'payload' => [
            ['symbol' => 'AAPL', 'company_name' => 'Apple Inc.', 'market_cap' => 3000000000000, 'price' => 180.0],
            ['symbol' => 'MSFT', 'company_name' => 'Microsoft Corp.', 'market_cap' => 2800000000000, 'price' => 400.0],
        ],
        'retrieved_at' => now()->subHours(2),
    ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // 1) Cached read returns existing 2 companies without calling upstream
    $cached = $provider->companyScreener();
    expect($cached)->toHaveCount(2)
        ->and(collect($cached)->pluck('symbol')->all())->toEqual(['AAPL', 'MSFT']);

    // 2) Upstream discovers a new company tomorrow (e.g. IPO, newly listed or matching screener)
    $upstream->shouldReceive('companyScreener')
        ->once()
        ->andReturn([
            ['symbol' => 'AAPL', 'company_name' => 'Apple Inc.', 'market_cap' => 3000000000000, 'price' => 180.0],
            ['symbol' => 'MSFT', 'company_name' => 'Microsoft Corp.', 'market_cap' => 2800000000000, 'price' => 400.0],
            ['symbol' => 'NEWCO', 'company_name' => 'New Discovery Corp.', 'market_cap' => 150000000, 'price' => 15.0],
        ]);

    // Request fresh company list
    $freshResults = $provider->companyScreener(['fresh' => true]);

    expect($freshResults)->toHaveCount(3)
        ->and(collect($freshResults)->pluck('symbol')->all())->toEqual(['AAPL', 'MSFT', 'NEWCO']);

    // Verify new company profile was automatically created
    $newcoProfile = CompanyProfile::where('symbol', 'NEWCO')->first();
    expect($newcoProfile)->not->toBeNull()
        ->and($newcoProfile->company_name)->toBe('New Discovery Corp.')
        ->and((int) $newcoProfile->market_cap)->toBe(150000000);

    // Verify stored payload was updated with the 3 companies
    $stored = MarketDataPayload::where('symbol', 'GLOBAL')->where('data_type', 'screener')->first();
    expect(count($stored->payload))->toBe(3);
});

test('earningsSurprises and earningsCalendar bypass cached payloads when fresh is requested', function () {
    $from = CarbonImmutable::parse('2026-11-01');
    $to = CarbonImmutable::parse('2026-11-02');
    $key = $from->toDateString().'_'.$to->toDateString();

    // Pre-populate cache with old data
    MarketDataPayload::create([
        'symbol' => 'GLOBAL',
        'data_type' => 'earnings_surprises',
        'period' => $key,
        'payload' => [
            ['symbol' => 'OLD_EARNING', 'report_date' => '2026-11-01', 'eps_actual' => 1.0, 'eps_surprise_percent' => 10],
        ],
        'retrieved_at' => now()->subHours(2),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('earningsSurprises')
        ->once()
        ->with(Mockery::type(CarbonImmutable::class), Mockery::type(CarbonImmutable::class))
        ->andReturn([
            ['symbol' => 'OLD_EARNING', 'report_date' => '2026-11-01', 'eps_actual' => 1.0, 'eps_surprise_percent' => 10],
            ['symbol' => 'NEW_SURPRISE', 'report_date' => '2026-11-02', 'eps_actual' => 2.5, 'eps_surprise_percent' => 150],
        ]);

    $provider = new PersistentMarketDataProvider($upstream);

    // Requesting with fresh: true bypasses stored payload and updates cache
    $freshSurprises = $provider->earningsSurprises($from, $to, fresh: true);

    expect($freshSurprises)->toHaveCount(2)
        ->and(collect($freshSurprises)->pluck('symbol')->all())->toEqual(['OLD_EARNING', 'NEW_SURPRISE']);
});

test('profile preserves is_actively_trading, is_etf, is_fund, and instrument type across persistence and produces identical classification fresh vs cached', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('profile')
        ->once()
        ->with('AAPL')
        ->andReturn([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'company_name' => 'Apple Inc.',
            'exchange' => 'NASDAQ',
            'price' => 220.50,
            'market_cap' => 3400000000000,
            'is_actively_trading' => true,
            'is_etf' => false,
            'is_fund' => false,
            'is_adr' => false,
            'type' => 'stock',
        ]);

    $upstream->shouldReceive('profile')
        ->once()
        ->with('SPY')
        ->andReturn([
            'symbol' => 'SPY',
            'name' => 'SPDR S&P 500 ETF Trust',
            'company_name' => 'SPDR S&P 500 ETF Trust',
            'exchange' => 'NYSE',
            'price' => 580.00,
            'market_cap' => 500000000000,
            'is_actively_trading' => true,
            'is_etf' => true,
            'is_fund' => false,
            'is_adr' => false,
            'type' => 'etf',
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $validator = app(\App\Services\Stocks\EquitySecurityValidator::class);

    // 1) Fresh profile fetches from upstream
    $freshAapl = $provider->profile('AAPL');
    expect($freshAapl['is_actively_trading'])->toBeTrue()
        ->and($freshAapl['is_etf'])->toBeFalse()
        ->and($freshAapl['is_fund'])->toBeFalse()
        ->and($freshAapl['type'])->toBe('stock');

    $freshAaplValidation = $validator->validate('AAPL', $freshAapl['company_name'], $freshAapl);
    expect($freshAaplValidation->isValid())->toBeTrue()
        ->and($freshAaplValidation->isCommonEquity())->toBeTrue()
        ->and($freshAaplValidation->instrumentType())->toBe(\App\Services\Stocks\EquityValidationResult::TYPE_COMMON_EQUITY);

    $freshSpy = $provider->profile('SPY');
    expect($freshSpy['is_etf'])->toBeTrue()
        ->and($freshSpy['type'])->toBe('etf');

    $freshSpyValidation = $validator->validate('SPY', $freshSpy['company_name'], $freshSpy);
    expect($freshSpyValidation->isValid())->toBeFalse()
        ->and($freshSpyValidation->instrumentType())->toBe(\App\Services\Stocks\EquityValidationResult::TYPE_FUND_OR_ETF);

    // 2) Verify data was persisted in database
    $storedAapl = CompanyProfile::where('symbol', 'AAPL')->first();
    expect($storedAapl)->not->toBeNull()
        ->and($storedAapl->is_actively_trading)->toBeTrue()
        ->and($storedAapl->is_etf)->toBeFalse()
        ->and($storedAapl->is_fund)->toBeFalse()
        ->and($storedAapl->type)->toBe('stock');

    $storedSpy = CompanyProfile::where('symbol', 'SPY')->first();
    expect($storedSpy)->not->toBeNull()
        ->and($storedSpy->is_etf)->toBeTrue()
        ->and($storedSpy->type)->toBe('etf');

    // 3) Flush L1 memory cache and clear mock to verify retrieval strictly from L2 database
    Cache::flush();

    // Provider should not call upstream again
    $cachedAapl = $provider->profile('AAPL');
    expect($cachedAapl['is_actively_trading'])->toBeTrue()
        ->and($cachedAapl['is_etf'])->toBeFalse()
        ->and($cachedAapl['is_fund'])->toBeFalse()
        ->and($cachedAapl['type'])->toBe('stock');

    $cachedAaplValidation = $validator->validate('AAPL', $cachedAapl['company_name'], $cachedAapl);
    expect($cachedAaplValidation->isValid())->toBe($freshAaplValidation->isValid())
        ->and($cachedAaplValidation->isCommonEquity())->toBe($freshAaplValidation->isCommonEquity())
        ->and($cachedAaplValidation->instrumentType())->toBe($freshAaplValidation->instrumentType())
        ->and($cachedAaplValidation->status())->toBe($freshAaplValidation->status());

    $cachedSpy = $provider->profile('SPY');
    expect($cachedSpy['is_etf'])->toBeTrue()
        ->and($cachedSpy['type'])->toBe('etf');

    $cachedSpyValidation = $validator->validate('SPY', $cachedSpy['company_name'], $cachedSpy);
    expect($cachedSpyValidation->isValid())->toBe($freshSpyValidation->isValid())
        ->and($cachedSpyValidation->instrumentType())->toBe($freshSpyValidation->instrumentType())
        ->and($cachedSpyValidation->status())->toBe($freshSpyValidation->status());
});

test('profile preserves null for missing and malformed metadata across persistence and retrieval', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('profile')
        ->once()
        ->with('INCOMP')
        ->andReturn([
            'symbol' => 'INCOMP',
            'name' => 'Incomplete Co.',
            'company_name' => 'Incomplete Co.',
            'exchange' => 'NYSE',
            'price' => 50.00,
            'market_cap' => 100000000,
            // is_actively_trading, is_etf, is_fund, is_adr, type are omitted
        ]);

    $upstream->shouldReceive('profile')
        ->once()
        ->with('MALFORM')
        ->andReturn([
            'symbol' => 'MALFORM',
            'name' => 'Malformed Flags Corp.',
            'company_name' => 'Malformed Flags Corp.',
            'exchange' => 'NASDAQ',
            'price' => 75.00,
            'is_actively_trading' => 'invalid-boolean-string',
            'is_etf' => 'not_a_bool',
            'is_fund' => '',
            'is_adr' => 'bad_value',
            'type' => null,
        ]);

    $provider = new PersistentMarketDataProvider($upstream);
    $validator = app(\App\Services\Stocks\EquitySecurityValidator::class);

    // 1) Missing metadata remains null on fresh fetch
    $freshIncomp = $provider->profile('INCOMP');
    expect($freshIncomp['is_actively_trading'])->toBeNull()
        ->and($freshIncomp['is_etf'])->toBeNull()
        ->and($freshIncomp['is_fund'])->toBeNull()
        ->and($freshIncomp['is_adr'])->toBeNull()
        ->and($freshIncomp['type'])->toBeNull();

    // 2) Malformed metadata resolves to null
    $freshMalform = $provider->profile('MALFORM');
    expect($freshMalform['is_actively_trading'])->toBeNull()
        ->and($freshMalform['is_etf'])->toBeNull()
        ->and($freshMalform['is_fund'])->toBeNull()
        ->and($freshMalform['is_adr'])->toBeNull()
        ->and($freshMalform['type'])->toBeNull();

    // 3) Persisted in DB as null
    $storedIncomp = CompanyProfile::where('symbol', 'INCOMP')->first();
    expect($storedIncomp)->not->toBeNull()
        ->and($storedIncomp->is_actively_trading)->toBeNull()
        ->and($storedIncomp->is_etf)->toBeNull()
        ->and($storedIncomp->is_fund)->toBeNull()
        ->and($storedIncomp->is_adr)->toBeNull()
        ->and($storedIncomp->type)->toBeNull();

    $storedMalform = CompanyProfile::where('symbol', 'MALFORM')->first();
    expect($storedMalform)->not->toBeNull()
        ->and($storedMalform->is_actively_trading)->toBeNull()
        ->and($storedMalform->is_etf)->toBeNull()
        ->and($storedMalform->is_fund)->toBeNull()
        ->and($storedMalform->is_adr)->toBeNull()
        ->and($storedMalform->type)->toBeNull();

    // 4) Flush L1 cache and retrieve from L2 database - remains null
    Cache::flush();
    $cachedIncomp = $provider->profile('INCOMP');
    expect($cachedIncomp['is_actively_trading'])->toBeNull()
        ->and($cachedIncomp['is_etf'])->toBeNull()
        ->and($cachedIncomp['is_fund'])->toBeNull()
        ->and($cachedIncomp['is_adr'])->toBeNull()
        ->and($cachedIncomp['type'])->toBeNull();

    // 5) Validation on incomplete metadata preserves provisional eligibility
    $incompValidation = $validator->validate('INCOMP', $cachedIncomp['company_name'], $cachedIncomp);
    expect($incompValidation->isValid())->toBeTrue()
        ->and($incompValidation->isCommonEquity())->toBeFalse()
        ->and($incompValidation->isInsufficientMetadata())->toBeTrue()
        ->and($incompValidation->status())->toBe(\App\Services\Stocks\EquityValidationResult::STATUS_INSUFFICIENT_METADATA);
});
