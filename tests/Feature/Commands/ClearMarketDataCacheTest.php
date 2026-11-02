<?php

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();

    // Seed AAPL data in permanent database
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

    // Seed MSFT data in permanent database
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
});

test('market-data:clear validates type option', function () {
    $this->artisan('market-data:clear --type=invalid')
        ->assertFailed()
        ->expectsOutputToContain('Invalid --type [invalid]');
});

test('market-data:clear with type flag performs granular memory cache invalidation', function () {
    $symbol = 'AAPL';
    // Set cached entries for quote and profile
    Cache::put("fmp.quote.{$symbol}", ['price' => 150.0], 3600);
    Cache::put("duoasset.profile.{$symbol}", ['company_name' => 'Apple'], 3600);

    // Invalidate quote only
    $this->artisan("market-data:clear --symbol={$symbol} --type=quote")
        ->assertSuccessful()
        ->expectsOutputToContain('Market data memory cache (quote) successfully cleared for AAPL.');

    // Quote cache was forgotten
    expect(Cache::get("fmp.quote.{$symbol}"))->toBeNull()
        // Profile cache was preserved
        ->and(Cache::get("duoasset.profile.{$symbol}"))->not->toBeNull();
});

test('market-data:clear clears memory cache while preserving permanent database records', function () {
    // Set unrelated application cache key
    Cache::put('unrelated_app_setting', 'preserve_me', 3600);

    $this->artisan('market-data:clear --symbol=AAPL')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data memory cache successfully cleared for AAPL.')
        ->expectsOutputToContain('Permanent database records were preserved.');

    // Permanent database records must NEVER be deleted by normal cache clearing
    expect(StockDailyBar::where('symbol', 'AAPL')->count())->toBe(1)
        ->and(CompanyProfile::where('symbol', 'AAPL')->count())->toBe(1)
        ->and(CompanyFinancial::where('symbol', 'AAPL')->count())->toBe(1)
        ->and(MarketDataPayload::where('symbol', 'AAPL')->count())->toBe(1)
        // MSFT preserved
        ->and(StockDailyBar::where('symbol', 'MSFT')->count())->toBe(1)
        // Unrelated application cache was NOT wiped
        ->and(Cache::get('unrelated_app_setting'))->toBe('preserve_me');
});

test('global market-data:clear preserves all database records across symbols', function () {
    Cache::put('another_user_session', 'active_session', 3600);

    $this->artisan('market-data:clear')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data memory cache successfully cleared for all symbols.')
        ->expectsOutputToContain('Permanent database records were preserved.');

    // Database records intact
    expect(StockDailyBar::count())->toBe(2)
        ->and(CompanyProfile::count())->toBe(1)
        ->and(CompanyFinancial::count())->toBe(1)
        ->and(MarketDataPayload::count())->toBe(1)
        ->and(Cache::get('another_user_session'))->toBe('active_session');
});

test('market-data:clear supports granular invalidation for estimates, earnings, and screener', function () {
    $vEst = \App\Services\MarketData\PersistentMarketDataProvider::getCacheVersion('estimates', 'AAPL');
    $vEarn = \App\Services\MarketData\PersistentMarketDataProvider::getCacheVersion('earnings', 'GLOBAL');
    $vScreen = \App\Services\MarketData\PersistentMarketDataProvider::getCacheVersion('screener', 'GLOBAL');

    $this->artisan('market-data:clear --symbol=AAPL --type=estimates')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data memory cache (estimates) successfully cleared for AAPL.');

    $newEst = \App\Services\MarketData\PersistentMarketDataProvider::getCacheVersion('estimates', 'AAPL');
    expect($newEst)->toBeGreaterThan($vEst);

    $this->artisan('market-data:clear --type=earnings')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data memory cache (earnings) successfully cleared for all symbols.');

    $newEarn = \App\Services\MarketData\PersistentMarketDataProvider::getCacheVersion('earnings', 'GLOBAL');
    expect($newEarn)->toBeGreaterThan($vEarn);

    $this->artisan('market-data:clear --type=screener')
        ->assertSuccessful()
        ->expectsOutputToContain('Market data memory cache (screener) successfully cleared for all symbols.');

    $newScreen = \App\Services\MarketData\PersistentMarketDataProvider::getCacheVersion('screener', 'GLOBAL');
    expect($newScreen)->toBeGreaterThan($vScreen);
});
