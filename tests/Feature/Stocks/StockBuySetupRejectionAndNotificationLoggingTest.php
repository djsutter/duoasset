<?php

use App\Jobs\EvaluateStockBuySetup;
use App\Jobs\SendStockBuySetupAlert;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Notifications\StockBuySetupDetected;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\Algorithms\BuySetupAlgorithmRegistry;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupLiquidityPenalty;
use App\Services\Stocks\StockBuySetupScanner;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\StockProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('stock buy setup scanner captures rejection reasons when market cap is below setup minimum', function () {
    $scanner = app(StockBuySetupScanner::class);

    // Oceanic Iron Ore (FEO) scenario: market cap ~10,000,000 against a 50,000,000 default min
    $bars = [];
    $baseDate = CarbonImmutable::parse('2025-01-01');
    for ($i = 0; $i < 300; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 1.0,
            'high' => 1.1,
            'low' => 0.9,
            'close' => 1.0,
            'volume' => 50000,
        ];
    }

    $results = $scanner->evaluateAll($bars, $bars, [
        'symbol' => 'FEO',
        'exchange' => 'TSXV',
        'market_cap' => 10000000,
    ]);

    expect($results)->toBeEmpty();
    expect($scanner->lastRejectionReason())->toContain('market cap below setup minimum');
    expect($scanner->lastRejectionReasons())->toHaveKey('heartbeat_consolidation_spike');
});

test('evaluate stock buy setup job logs rejection when symbol fails gates', function () {
    Log::spy();

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('profile')->andReturn([
        'company_name' => 'Oceanic Iron Ore Corp',
        'exchange' => 'TSXV',
        'market_cap' => 10000000,
        'price' => 0.10,
    ]);

    $baseDate = CarbonImmutable::parse('2025-01-01');
    $bars = [];
    for ($i = 0; $i < 300; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 0.10,
            'high' => 0.11,
            'low' => 0.09,
            'close' => 0.10,
            'volume' => 20000,
        ];
    }

    $mockProvider->shouldReceive('historicalDailyBars')->andReturn($bars);
    $mockProvider->shouldReceive('quarterlyIncomeStatements')->andReturn([]);
    $mockProvider->shouldReceive('quarterlyBalanceSheets')->andReturn([]);
    $mockProvider->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

    $job = new EvaluateStockBuySetup('FEO', 'Oceanic Iron Ore Corp', 'TSXV', 10000000);
    $result = $job->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
    );

    expect($result['status'])->toBe('rejected')
        ->and($result['reason'])->toContain('market cap below setup minimum');

    Log::shouldHaveReceived('info')
        ->with('buy_setup.rejected', Mockery::on(function ($context) {
            return ($context['symbol'] ?? null) === 'FEO'
                && ($context['exchange'] ?? null) === 'TSXV'
                && ($context['market_cap'] ?? null) === 10000000
                && str_contains($context['reason'] ?? '', 'market cap below setup minimum');
        }))
        ->once();
});

test('send stock buy setup alert job logs notification steps and delivers to users and extra email', function () {
    Notification::fake();

    $user = User::factory()->create([
        'notify_stock_buy_setup' => true,
    ]);

    $alert = StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'TEST',
        'company_name' => 'Test Corp',
        'exchange' => 'NASDAQ',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 85,
        'raw_setup_score' => 85,
        'heartbeat_score' => 85,
        'spike_date' => '2026-01-15',
        'base_start_date' => '2025-10-01',
        'base_end_date' => '2026-01-14',
        'base_duration_days' => 75,
        'range_compression_pct' => 15.0,
        'atr_contraction_ratio' => 0.65,
        'distance_to_breakout_pct' => 2.0,
        'reason_summary' => 'Solid setup detected',
        'status' => 'new',
        'detected_at' => now(),
    ]);

    config(['market_data.buy_setup_scanner.notification_email' => 'admin@example.com']);
    $configService = app(BuySetupConfigService::class);
    $cfg = $configService->getConfig();
    $cfg['notification_email'] = 'admin@example.com';
    $configService->saveConfig($cfg);

    Log::shouldReceive('info')->with('buy_setup.notification_job_started', Mockery::any())->once();
    Log::shouldReceive('info')->with('buy_setup.notifying_user', Mockery::any())->once();
    Log::shouldReceive('info')->with('buy_setup.user_notified', Mockery::any())->once();
    Log::shouldReceive('info')->with('buy_setup.user_notifications_completed', Mockery::any())->once();
    Log::shouldReceive('info')->with('buy_setup.notifying_extra_email', Mockery::any())->once();
    Log::shouldReceive('info')->with('buy_setup.extra_email_notified', Mockery::any())->once();

    $job = new SendStockBuySetupAlert($alert->id);
    $job->handle();

    Notification::assertSentTo($user, StockBuySetupDetected::class);
    Notification::assertSentOnDemand(StockBuySetupDetected::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'admin@example.com';
    });

    $alert->refresh();
    expect($alert->status)->toBe('sent')
        ->and($alert->sent_at)->not->toBeNull();
});
