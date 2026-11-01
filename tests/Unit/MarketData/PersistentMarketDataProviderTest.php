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

test('resolves PersistentMarketDataProvider from service container when driver is fmp or persistent', function (string $driver) {
    config(['market_data.provider' => $driver]);

    $resolved = app(MarketDataProvider::class);
    expect($resolved)->toBeInstanceOf(PersistentMarketDataProvider::class);
})->with(['fmp', 'persistent']);
