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

test('command validates type option', function () {
    $this->artisan('market-data:clear --type=invalid')
        ->assertFailed()
        ->expectsOutputToContain('Invalid --type [invalid]');
});

test('clearing bars for specific symbol leaves other symbols and categories untouched', function () {
    $this->artisan('market-data:clear --symbol=AAPL --type=bars')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data cache successfully cleared.');

    expect(StockDailyBar::where('symbol', 'AAPL')->count())->toBe(0)
        ->and(StockDailyBar::where('symbol', 'MSFT')->count())->toBe(1)
        ->and(CompanyProfile::where('symbol', 'AAPL')->count())->toBe(1)
        ->and(CompanyFinancial::where('symbol', 'AAPL')->count())->toBe(1);
});

test('clearing financials for specific symbol only removes financials', function () {
    $this->artisan('market-data:clear --symbol=AAPL --type=financials')
        ->assertSuccessful();

    expect(CompanyFinancial::where('symbol', 'AAPL')->count())->toBe(0)
        ->and(StockDailyBar::where('symbol', 'AAPL')->count())->toBe(1)
        ->and(CompanyProfile::where('symbol', 'AAPL')->count())->toBe(1);
});

test('clearing all data for specific symbol purges all its categories', function () {
    $this->artisan('market-data:clear --symbol=AAPL --type=all')
        ->assertSuccessful();

    expect(StockDailyBar::where('symbol', 'AAPL')->count())->toBe(0)
        ->and(CompanyProfile::where('symbol', 'AAPL')->count())->toBe(0)
        ->and(CompanyFinancial::where('symbol', 'AAPL')->count())->toBe(0)
        ->and(MarketDataPayload::where('symbol', 'AAPL')->count())->toBe(0)
        // MSFT preserved
        ->and(StockDailyBar::where('symbol', 'MSFT')->count())->toBe(1)
        ->and(CompanyProfile::where('symbol', 'MSFT')->count())->toBe(1);
});

test('clearing all data globally purges across all symbols with force flag', function () {
    $this->artisan('market-data:clear --force')
        ->assertSuccessful();

    expect(StockDailyBar::count())->toBe(0)
        ->and(CompanyProfile::count())->toBe(0)
        ->and(CompanyFinancial::count())->toBe(0)
        ->and(MarketDataPayload::count())->toBe(0);
});
