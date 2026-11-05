<?php

use App\Jobs\EvaluateStockBuySetup;
use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\IgnitionExtensionCalculator;
use App\Services\Stocks\StockBuySetupLiquidityPenalty;
use App\Services\Stocks\StockBuySetupScanner;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\StockProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('EvaluateStockBuySetup rejects BWNB as redeemed bond before calculating scores and clears existing false-positive alerts', function () {
    // Seed an existing false-positive alert for BWNB to verify it gets cleaned up
    $existingAlert = StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'BWNB',
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_date' => CarbonImmutable::today()->subDays(10),
        'base_start_date' => CarbonImmutable::today()->subDays(80),
        'base_end_date' => CarbonImmutable::today()->subDays(10),
        'raw_score' => 88,
        'setup_score' => 88,
    ]);

    expect(StockBuySetupAlert::where('symbol', 'BWNB')->count())->toBe(1);

    $scanRun = StockBuySetupRun::create([
        'started_at' => now(),
        'status' => 'running',
        'trigger' => 'cli-sync',
        'symbols_considered' => 1,
    ]);

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('profile')->with('BWNB')->andReturn([
        'company_name' => 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
        'exchange' => 'NYSE',
        'price' => 25.00,
        'is_actively_trading' => false,
    ]);

    // Ensure heavy bars/fundamentals are NEVER called for non-equity instruments
    $mockProvider->shouldNotReceive('historicalDailyBars');
    $mockProvider->shouldNotReceive('quarterlyIncomeStatements');
    $mockProvider->shouldNotReceive('quarterlyBalanceSheets');
    $mockProvider->shouldNotReceive('quarterlyCashFlowStatements');

    $job = new EvaluateStockBuySetup(
        symbol: 'BWNB',
        companyName: 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
        exchange: 'NYSE',
        scanRunId: $scanRun->id,
    );

    $debug = $job->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
        app(IgnitionExtensionCalculator::class),
    );

    expect($debug['status'])->toBe('rejected')
        ->and($debug['reason'])->toContain('BWNB is a redeemed bond')
        ->and($debug['reason'])->toContain('actual common equity is BW')
        ->and($debug['instrument_type'])->toBe('redeemed_bond')
        ->and($debug['underlying_symbol'])->toBe('BW')
        ->and($debug['bars'])->toBe(0);

    // Existing false-positive alert for BWNB must be cleared
    expect(StockBuySetupAlert::where('symbol', 'BWNB')->count())->toBe(0);

    $scanRun->refresh();
    expect($scanRun->rejected_count)->toBe(1)
        ->and($scanRun->categorized_reasons[StockBuySetupRun::CATEGORY_NON_EQUITY_INSTRUMENT] ?? 0)->toBe(1);
});

test('EvaluateStockBuySetup rejects preferred shares and warrants without calculating setup scores', function () {
    $mockProvider = Mockery::mock(MarketDataProvider::class);

    $mockProvider->shouldReceive('profile')->with('BW-A')->andReturn([
        'company_name' => 'Babcock & Wilcox Enterprises 7.75% Series A Cumulative Perpetual Preferred Stock',
        'exchange' => 'NYSE',
        'price' => 24.50,
    ]);

    $mockProvider->shouldReceive('profile')->with('LCIDW')->andReturn([
        'company_name' => 'Lucid Group, Inc. Warrants',
        'exchange' => 'NASDAQ',
        'price' => 1.20,
    ]);

    $mockProvider->shouldNotReceive('historicalDailyBars');
    $mockProvider->shouldNotReceive('quarterlyIncomeStatements');

    $preferredJob = new EvaluateStockBuySetup(symbol: 'BW-A');
    $prefDebug = $preferredJob->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
        app(IgnitionExtensionCalculator::class),
    );

    expect($prefDebug['status'])->toBe('rejected')
        ->and($prefDebug['reason'])->toContain('preferred stock')
        ->and($prefDebug['instrument_type'])->toBe('preferred_stock');

    $warrantJob = new EvaluateStockBuySetup(symbol: 'LCIDW');
    $warrantDebug = $warrantJob->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
        app(IgnitionExtensionCalculator::class),
    );

    expect($warrantDebug['status'])->toBe('rejected')
        ->and($warrantDebug['reason'])->toContain('warrant')
        ->and($warrantDebug['instrument_type'])->toBe('warrant');
});

test('StockBuySetupScanner evaluateAll rejects non-equity instruments directly', function () {
    $scanner = app(StockBuySetupScanner::class);

    // Synthetic bars that would otherwise match a pattern
    $bars = [];
    $baseDate = CarbonImmutable::parse('2025-01-01');
    for ($i = 0; $i < 260; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 10.0,
            'high' => 10.5,
            'low' => 9.5,
            'close' => 10.0,
            'volume' => 100000,
        ];
    }

    $results = $scanner->evaluateAll($bars, [], [
        'symbol' => 'BWNB',
        'company_name' => 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
    ]);

    expect($results)->toBeEmpty()
        ->and($scanner->lastRejectionReason())->toContain('BWNB is a redeemed bond');
});

test('stocks:scan-buy-setups CLI command rejects explicit BWNB run with informative reason', function () {
    $this->artisan('stocks:scan-buy-setups', [
        '--symbol' => ['BWNB'],
        '--sync' => true,
    ])
        ->expectsOutputToContain('Processed: 1 per-symbol buy-setup evaluations')
        ->assertExitCode(0);
});

test('stocks:scan-buy-setups excludes non-equity candidates from screener universe', function () {
    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->andReturnNull();
    $mockProvider->shouldReceive('lastErrors')->andReturn([]);
    $mockProvider->shouldReceive('companyScreener')->andReturn([
        [
            'symbol' => 'BWNB',
            'company_name' => 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
            'exchange' => 'NYSE',
            'market_cap' => 100000000,
        ],
        [
            'symbol' => 'BW-A',
            'company_name' => 'Babcock & Wilcox 7.75% Preferred',
            'exchange' => 'NYSE',
            'market_cap' => 50000000,
        ],
        [
            'symbol' => 'BW',
            'company_name' => 'Babcock & Wilcox Enterprises, Inc.',
            'exchange' => 'NYSE',
            'market_cap' => 200000000,
        ],
    ]);

    app()->instance(MarketDataProvider::class, $mockProvider);

    // Only BW should be dispatched; BWNB and BW-A should be filtered out
    \Illuminate\Support\Facades\Queue::fake();

    $this->artisan('stocks:scan-buy-setups', [
        '--exchange' => 'NYSE',
    ])
        ->expectsOutputToContain('Dispatched: 1 per-symbol buy-setup evaluations')
        ->assertExitCode(0);

    \Illuminate\Support\Facades\Queue::assertPushed(EvaluateStockBuySetup::class, function ($job) {
        return $job->symbol === 'BW';
    });

    \Illuminate\Support\Facades\Queue::assertNotPushed(EvaluateStockBuySetup::class, function ($job) {
        return in_array($job->symbol, ['BWNB', 'BW-A'], true);
    });
});

test('stocks:scan-buy-setups removes existing false-positive alerts and tracks rejections during screener pre-filtering', function () {
    // Seed existing false-positive alerts
    StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'BWNB',
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_date' => CarbonImmutable::today()->subDays(10),
        'base_start_date' => CarbonImmutable::today()->subDays(80),
        'base_end_date' => CarbonImmutable::today()->subDays(10),
        'raw_score' => 88,
        'setup_score' => 88,
    ]);
    StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'BW-A',
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_date' => CarbonImmutable::today()->subDays(10),
        'base_start_date' => CarbonImmutable::today()->subDays(80),
        'base_end_date' => CarbonImmutable::today()->subDays(10),
        'raw_score' => 85,
        'setup_score' => 85,
    ]);

    expect(StockBuySetupAlert::whereIn('symbol', ['BWNB', 'BW-A'])->count())->toBe(2);

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->andReturnNull();
    $mockProvider->shouldReceive('lastErrors')->andReturn([]);
    $mockProvider->shouldReceive('companyScreener')->andReturn([
        [
            'symbol' => 'BWNB',
            'company_name' => 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
            'exchange' => 'NYSE',
            'market_cap' => 100000000,
        ],
        [
            'symbol' => 'BW-A',
            'company_name' => 'Babcock & Wilcox 7.75% Preferred',
            'exchange' => 'NYSE',
            'market_cap' => 50000000,
        ],
    ]);

    app()->instance(MarketDataProvider::class, $mockProvider);

    $this->artisan('stocks:scan-buy-setups', [
        '--exchange' => 'NYSE',
    ])->assertExitCode(0);

    // Existing alerts must have been cleaned up
    expect(StockBuySetupAlert::where('symbol', 'BWNB')->exists())->toBeFalse()
        ->and(StockBuySetupAlert::where('symbol', 'BW-A')->exists())->toBeFalse();

    // Verify rejection visibility in scan statistics
    $run = StockBuySetupRun::latest()->first();
    expect($run)->not->toBeNull()
        ->and($run->rejected_count)->toBe(2)
        ->and($run->categorized_reasons[StockBuySetupRun::CATEGORY_NON_EQUITY_INSTRUMENT] ?? 0)->toBe(2);
});

test('stocks:scan-buy-setups and EvaluateStockBuySetup bypass equity validation when disabled', function () {
    config(['market_data.buy_setup_scanner.equity_validation_enabled' => false]);

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->andReturnNull();
    $mockProvider->shouldReceive('lastErrors')->andReturn([]);
    $mockProvider->shouldReceive('companyScreener')->andReturn([
        [
            'symbol' => 'BWNB',
            'company_name' => 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
            'exchange' => 'NYSE',
            'market_cap' => 100000000,
        ],
    ]);

    app()->instance(MarketDataProvider::class, $mockProvider);

    \Illuminate\Support\Facades\Queue::fake();

    $this->artisan('stocks:scan-buy-setups', [
        '--exchange' => 'NYSE',
    ])
        ->expectsOutputToContain('Dispatched: 1 per-symbol buy-setup evaluations')
        ->assertExitCode(0);

    \Illuminate\Support\Facades\Queue::assertPushed(EvaluateStockBuySetup::class, function ($job) {
        return $job->symbol === 'BWNB';
    });

    // Now test EvaluateStockBuySetup job execution with validation disabled
    $jobProvider = Mockery::mock(MarketDataProvider::class);
    $jobProvider->shouldReceive('profile')->with('BWNB')->andReturn([
        'company_name' => 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
        'exchange' => 'NYSE',
        'price' => 25.00,
        'is_actively_trading' => false,
    ]);
    // With equity validation disabled, it proceeds to fetch bars
    $jobProvider->shouldReceive('historicalDailyBars')->with('BWNB', Mockery::any(), Mockery::any())->andReturn([]);

    $job = new EvaluateStockBuySetup(
        symbol: 'BWNB',
        companyName: 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026',
        exchange: 'NYSE',
    );

    $debug = $job->handle(
        $jobProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
        app(IgnitionExtensionCalculator::class),
    );

    // Job was not rejected at the equity validation gate; it proceeded to history check
    expect($debug['status'])->toBe('rejected')
        ->and($debug['category'])->toBe('insufficient_history')
        ->and($debug)->not->toHaveKey('instrument_type');
});
