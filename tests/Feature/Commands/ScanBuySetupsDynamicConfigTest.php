<?php

use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('scan buy setups command respects disabled scanner setting in config service', function () {
    $service = app(BuySetupConfigService::class);
    $config = $service->getConfig();
    $config['scanner_enabled'] = false;
    $service->saveConfig($config);

    $this->artisan('stocks:scan-buy-setups')
        ->expectsOutput('Stock Buy Setup scanner is disabled (BUY_SETUP_SCANNER_ENABLED=false).')
        ->assertSuccessful();
});

test('scan buy setups command runs with dynamic config and screener options', function () {
    $service = app(BuySetupConfigService::class);
    $config = $service->getConfig();
    $config['min_market_cap'] = 50000000;
    $config['exchanges'] = ['NASDAQ'];
    $service->saveConfig($config);

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->zeroOrMoreTimes();
    $mockProvider->shouldReceive('companyScreener')
        ->once()
        ->with(Mockery::on(function ($filters) {
            return ($filters['marketCapMoreThan'] ?? null) === 50000000
                && ($filters['exchange'] ?? null) === ['NASDAQ'];
        }))
        ->andReturn([
            ['symbol' => 'TEST1', 'company_name' => 'Test Corp', 'exchange' => 'NASDAQ', 'market_cap' => 60000000],
        ]);

    $this->app->instance(MarketDataProvider::class, $mockProvider);

    $this->artisan('stocks:scan-buy-setups', ['--sync' => true])
        ->assertSuccessful();
});

test('scan buy setups command widens the screener market-cap range to span every enabled setup type', function () {
    $service = app(BuySetupConfigService::class);
    $config = $service->getConfig();
    $config['exchanges'] = ['NASDAQ'];

    // Only these two setup types are enabled; their min/max should
    // determine the widened screener query range.
    $config['setup_types']['heartbeat_consolidation_spike']['enabled'] = true;
    $config['setup_types']['heartbeat_consolidation_spike']['min_market_cap'] = 50000000;
    $config['setup_types']['heartbeat_consolidation_spike']['max_market_cap'] = 1000000000;

    $config['setup_types']['range_compression_breakout']['enabled'] = true;
    $config['setup_types']['range_compression_breakout']['min_market_cap'] = 100000000;
    $config['setup_types']['range_compression_breakout']['max_market_cap'] = 100000000000;

    $config['setup_types']['floor_reversal_accumulation']['enabled'] = false;
    $config['setup_types']['early_breakout_followthrough']['enabled'] = false;

    $service->saveConfig($config);

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->zeroOrMoreTimes();
    $mockProvider->shouldReceive('companyScreener')
        ->once()
        ->with(Mockery::on(function ($filters) {
            // Widest min (50M) and widest max (100B) across enabled setup types.
            return ($filters['marketCapMoreThan'] ?? null) === 50000000
                && ($filters['marketCapLowerThan'] ?? null) === 100000000000
                && ($filters['exchange'] ?? null) === ['NASDAQ'];
        }))
        ->andReturn([]);

    $this->app->instance(MarketDataProvider::class, $mockProvider);

    $this->artisan('stocks:scan-buy-setups', ['--sync' => true])
        ->assertSuccessful();
});

test('scanner only evaluates enabled setup types and respects their custom configurations', function () {
    $service = app(BuySetupConfigService::class);
    $config = $service->getConfig();

    // Enable heartbeat and custom sales_acceleration; disable range_compression_breakout
    $config['setup_types']['heartbeat_consolidation_spike']['enabled'] = true;
    $config['setup_types']['range_compression_breakout']['enabled'] = false;

    $salesType = $service->createDefaultSetupType('sales_acceleration', 'Sales Acceleration');
    $salesType['enabled'] = true;
    $salesType['min_base_days'] = 20;
    $salesType['score_weights']['sales_acceleration']['weight'] = 50;
    $config['setup_types']['sales_acceleration'] = $salesType;

    $disabledType = $service->createDefaultSetupType('disabled_custom', 'Disabled Custom');
    $disabledType['enabled'] = false;
    $config['setup_types']['disabled_custom'] = $disabledType;

    $service->saveConfig($config);

    // Build bars: 504 bars with high volume spike and consolidation
    $bars = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2025-01-01');
    for ($i = 0; $i < 504; $i++) {
        $date = $baseDate->addDays($i)->toDateString();
        $vol = 100_000;
        $close = 50.0;
        $high = 50.5;
        $low = 49.5;
        $open = 50.0;

        // Spike 40 bars ago
        if ($i === 504 - 40) {
            $vol = 1_000_000;
            $high = 60.0;
            $close = 58.0;
        } elseif ($i > 504 - 40) {
            // tight consolidation
            $close = 58.0 + (sin($i) * 0.2);
            $high = $close + 0.3;
            $low = $close - 0.3;
            $open = $close;
            $vol = 50_000;
        }

        $bars[] = [
            'date' => $date,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $vol,
        ];
    }

    $scanner = app(StockBuySetupScanner::class);
    $results = $scanner->evaluateAll($bars, $bars, [
        'symbol' => 'MULTI1',
        'company_name' => 'Multi Corp',
        'exchange' => 'NASDAQ',
        'market_cap' => 500_000_000,
    ]);

    $resultTypes = array_map(fn ($r) => $r->setupType, $results);

    // Should include enabled types: heartbeat_consolidation_spike and sales_acceleration
    expect($resultTypes)->toContain('heartbeat_consolidation_spike')
        ->and($resultTypes)->toContain('sales_acceleration')
        // Should NOT include disabled types
        ->and($resultTypes)->not->toContain('range_compression_breakout')
        ->and($resultTypes)->not->toContain('disabled_custom');
});
