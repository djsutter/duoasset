<?php

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();

    // Seed AAPL data
    StockDailyBar::create([
        'symbol' => 'AAPL',
        'bar_date' => '2026-10-01',
        'open' => 150.0,
        'high' => 155.0,
        'low' => 149.0,
        'close' => 154.0,
        'adj_close' => 154.0,
        'volume' => 1000000,
    ]);
    CompanyProfile::create([
        'symbol' => 'AAPL',
        'company_name' => 'Apple Inc.',
    ]);
    CompanyFinancial::create([
        'symbol' => 'AAPL',
        'statement_type' => CompanyFinancial::TYPE_INCOME,
        'fiscal_date' => '2026-06-30',
        'period' => 'Q3',
        'revenue' => 85000000000,
    ]);
    MarketDataPayload::create([
        'symbol' => 'AAPL',
        'data_type' => 'bars',
        'period' => 'default',
        'payload' => ['sample' => true],
    ]);

    // Seed MSFT data
    StockDailyBar::create([
        'symbol' => 'MSFT',
        'bar_date' => '2026-10-01',
        'open' => 400.0,
        'high' => 405.0,
        'low' => 399.0,
        'close' => 403.0,
        'adj_close' => 403.0,
        'volume' => 2000000,
    ]);
    CompanyProfile::create([
        'symbol' => 'MSFT',
        'company_name' => 'Microsoft',
    ]);
});

test('purge command validates type option', function () {
    $this->artisan('market-data:purge-database --type=invalid --force')
        ->assertFailed()
        ->expectsOutputToContain('Invalid --type [invalid]');
});

test('purge command permanently deletes specific symbol and category with force flag', function () {
    $this->artisan('market-data:purge-database --symbol=AAPL --type=bars --force')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data records permanently deleted from the database.');

    expect(StockDailyBar::where('symbol', 'AAPL')->count())->toBe(0)
        ->and(StockDailyBar::where('symbol', 'MSFT')->count())->toBe(1)
        ->and(CompanyProfile::where('symbol', 'AAPL')->count())->toBe(1)
        ->and(CompanyFinancial::where('symbol', 'AAPL')->count())->toBe(1);
});

test('purge command requires confirmation when not forced', function () {
    // If not confirming with 'PURGE', operation is cancelled
    $this->artisan('market-data:purge-database')
        ->expectsQuestion("WARNING: This will PERMANENTLY delete market data records (all) for ALL symbols from the database. Type 'PURGE' to confirm", 'NO')
        ->assertSuccessful()
        ->expectsOutputToContain('Permanent deletion cancelled.');

    expect(StockDailyBar::count())->toBe(2);
});

test('purge command fails in non-interactive environment without force flag', function () {
    $this->artisan('market-data:purge-database --no-interaction')
        ->assertFailed()
        ->expectsOutputToContain('Permanent deletion in non-interactive environments requires explicit authorization with the --force flag.');

    // No records should be deleted
    expect(StockDailyBar::count())->toBe(2)
        ->and(CompanyProfile::count())->toBe(2);
});

test('purge command fails for single symbol in non-interactive environment without force flag', function () {
    $this->artisan('market-data:purge-database --symbol=AAPL --no-interaction')
        ->assertFailed()
        ->expectsOutputToContain('Permanent deletion in non-interactive environments requires explicit authorization with the --force flag.');

    expect(StockDailyBar::where('symbol', 'AAPL')->count())->toBe(1);
});

test('purge command permanently purges globally across all symbols when confirmed with force', function () {
    $this->artisan('market-data:purge-database --force')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data records permanently deleted from the database.');

    expect(StockDailyBar::count())->toBe(0)
        ->and(CompanyProfile::count())->toBe(0)
        ->and(CompanyFinancial::count())->toBe(0)
        ->and(MarketDataPayload::count())->toBe(0);
});

test('symbol-specific database purging never deletes shared screener or earnings data', function () {
    MarketDataPayload::create([
        'symbol' => 'GLOBAL',
        'data_type' => 'screener',
        'period' => 'test_filter',
        'payload' => [['symbol' => 'AAPL'], ['symbol' => 'MSFT']],
    ]);
    MarketDataPayload::create([
        'symbol' => 'GLOBAL',
        'data_type' => 'earnings_calendar',
        'period' => '2026-10-01_2026-10-07',
        'payload' => [['symbol' => 'AAPL'], ['symbol' => 'MSFT']],
    ]);

    // Purging AAPL with type=all or type=screener/earnings must NOT delete GLOBAL screener or earnings data
    $this->artisan('market-data:purge-database --symbol=AAPL --type=all --force')
        ->assertSuccessful();

    expect(MarketDataPayload::where('data_type', 'screener')->count())->toBe(1)
        ->and(MarketDataPayload::where('data_type', 'earnings_calendar')->count())->toBe(1);

    $this->artisan('market-data:purge-database --symbol=AAPL --type=screener --force')
        ->assertSuccessful();

    expect(MarketDataPayload::where('data_type', 'screener')->count())->toBe(1);
});
