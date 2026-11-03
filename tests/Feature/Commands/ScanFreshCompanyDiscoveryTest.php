<?php

use App\Models\CompanyProfile;
use App\Models\EarningsEvent;
use App\Models\MarketDataPayload;
use App\Models\StockBuySetupRun;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MarketData\PersistentMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

test('stocks:scan-buy-setups with --fresh flag fetches fresh screener and discovers new companies for setup scoring', function () {
    // 1) Pre-populate screener cache and database payload with yesterday's companies
    $filterHash = md5(json_encode([
        'marketCapMoreThan' => 10000000,
        'marketCapLowerThan' => 1000000000000,
        'exchange' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV', 'AMEX', 'OTC'],
        'limit' => null,
    ]));

    MarketDataPayload::create([
        'symbol' => 'GLOBAL',
        'data_type' => 'screener',
        'period' => $filterHash,
        'payload' => [
            ['symbol' => 'OLD1', 'company_name' => 'Old One Corp.', 'exchange' => 'NYSE', 'market_cap' => 500000000, 'price' => 50.0],
            ['symbol' => 'OLD2', 'company_name' => 'Old Two Corp.', 'exchange' => 'NASDAQ', 'market_cap' => 600000000, 'price' => 60.0],
        ],
        'retrieved_at' => now()->subHours(1),
    ]);

    // 2) Mock upstream to return a new company discovered tomorrow: NEW_DISCOVERY
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('clearErrors')->andReturnNull();
    $upstream->shouldReceive('lastErrors')->andReturn([]);
    $upstream->shouldReceive('companyScreener')
        ->once()
        ->andReturn([
            ['symbol' => 'OLD1', 'company_name' => 'Old One Corp.', 'exchange' => 'NYSE', 'market_cap' => 500000000, 'price' => 50.0],
            ['symbol' => 'OLD2', 'company_name' => 'Old Two Corp.', 'exchange' => 'NASDAQ', 'market_cap' => 600000000, 'price' => 60.0],
            ['symbol' => 'NEW_DISCOVERY', 'company_name' => 'New Discovery Inc.', 'exchange' => 'NASDAQ', 'market_cap' => 150000000, 'price' => 22.0],
        ]);

    // Mock historicalDailyBars for the per-symbol evaluation jobs
    $upstream->shouldReceive('historicalDailyBars')
        ->andReturn([
            ['date' => '2026-10-24', 'open' => 20.0, 'high' => 22.0, 'low' => 19.5, 'close' => 21.0, 'adj_close' => 21.0, 'volume' => 500000],
        ]);

    $persistentProvider = new PersistentMarketDataProvider($upstream);
    app()->instance(MarketDataProvider::class, $persistentProvider);

    // Run the command with --fresh and --sync
    $exitCode = Artisan::call('stocks:scan-buy-setups', [
        '--fresh' => true,
        '--sync' => true,
    ]);

    expect($exitCode)->toBe(0);

    // 3) Assert that the new company profile was automatically created and ingested
    $newcoProfile = CompanyProfile::where('symbol', 'NEW_DISCOVERY')->first();
    expect($newcoProfile)->not->toBeNull()
        ->and($newcoProfile->company_name)->toBe('New Discovery Inc.')
        ->and($newcoProfile->exchange)->toBe('NASDAQ');

    // 4) Assert that the setup scan run processed all 3 companies including the newly discovered one
    $lastRun = StockBuySetupRun::latest('id')->first();
    expect($lastRun)->not->toBeNull()
        ->and($lastRun->symbols_considered)->toBe(3);
});

test('earnings:scan-revisions with --fresh flag fetches fresh screener universe', function () {
    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('clearErrors')->andReturnNull();
    $upstream->shouldReceive('lastErrors')->andReturn([]);
    $upstream->shouldReceive('companyScreener')
        ->once()
        ->andReturn([
            ['symbol' => 'REV_NEW', 'company_name' => 'Revision Discovery Inc.', 'exchange' => 'NYSE', 'market_cap' => 200000000, 'price' => 35.0],
        ]);

    $upstream->shouldReceive('analystEstimates')
        ->andReturn([]);

    $persistentProvider = new PersistentMarketDataProvider($upstream);
    app()->instance(MarketDataProvider::class, $persistentProvider);

    $exitCode = Artisan::call('earnings:scan-revisions', [
        '--fresh' => true,
        '--sync' => true,
    ]);

    expect($exitCode)->toBe(0);

    // Assert that the newly discovered company profile was created in persistent storage
    $profile = CompanyProfile::where('symbol', 'REV_NEW')->first();
    expect($profile)->not->toBeNull()
        ->and($profile->company_name)->toBe('Revision Discovery Inc.');
});

test('earnings:scan-surprises with --fresh flag bypasses cached earnings and discovers new surprise rows', function () {
    $today = CarbonImmutable::parse('2026-11-02');
    $from = $today->subDay();
    $to = $today->addDay();
    $key = $from->toDateString() . '_' . $to->toDateString();

    // Stored earnings payload only has OLD_SURPRISE
    MarketDataPayload::create([
        'symbol' => 'GLOBAL',
        'data_type' => 'earnings_surprises',
        'period' => $key,
        'payload' => [
            ['symbol' => 'OLD_SURPRISE', 'report_date' => $from->toDateString(), 'eps_actual' => 1.0, 'eps_surprise_percent' => 5],
        ],
        'retrieved_at' => now()->subHours(1),
    ]);

    $upstream = Mockery::mock(MarketDataProvider::class);
    $upstream->shouldReceive('clearErrors')->andReturnNull();
    $upstream->shouldReceive('lastErrors')->andReturn([]);
    $upstream->shouldReceive('earningsSurprises')
        ->once()
        ->andReturn([
            ['symbol' => 'OLD_SURPRISE', 'report_date' => $from->toDateString(), 'eps_actual' => 1.0, 'eps_estimated' => 0.95, 'eps_surprise_percent' => 5],
            ['symbol' => 'NEW_DISCOVERED_EARNINGS', 'report_date' => $today->toDateString(), 'eps_actual' => 3.5, 'eps_estimated' => 1.5, 'eps_surprise_percent' => 120],
        ]);
    $upstream->shouldReceive('earningsCalendar')->andReturn([]);

    $persistentProvider = new PersistentMarketDataProvider($upstream);
    app()->instance(MarketDataProvider::class, $persistentProvider);

    $exitCode = Artisan::call('earnings:scan-surprises', [
        '--from' => $from->toDateString(),
        '--to' => $to->toDateString(),
        '--fresh' => true,
    ]);

    expect($exitCode)->toBe(0);

    // Verify EarningsEvent was created for the newly discovered company
    $event = EarningsEvent::where('symbol', 'NEW_DISCOVERED_EARNINGS')->first();
    expect($event)->not->toBeNull()
        ->and((float) $event->eps_actual)->toBe(3.5)
        ->and((float) $event->eps_surprise_percent)->toBeGreaterThan(100.0);
});
