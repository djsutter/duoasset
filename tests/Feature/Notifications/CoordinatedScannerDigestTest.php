<?php

use App\Models\EarningsAlert;
use App\Models\EarningsScanRun;
use App\Models\NotificationDigestDelivery;
use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Notifications\EpsSurpriseDigestNotification;
use App\Notifications\SetupScoringDigestNotification;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Notifications\ScannerDigestCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

test('nightly setup scan collection consolidates multiple exchange and letter slices into a single SetupScoringDigestNotification per subscriber', function () {
    Notification::fake();

    $subscriber = User::factory()->create([
        'email' => 'digest-sub@example.com',
        'notify_stock_buy_setup' => true,
        'email_setup_scoring_digest' => true,
    ]);

    $coordinator = app(ScannerDigestCoordinator::class);

    // 1. Start collection (as scripts/duoasset-setup-scan.sh does before the exchange/letter loop)
    $this->artisan('digest:start-collection', ['category' => 'setup_scoring'])
        ->assertSuccessful();

    $activeRunId = $coordinator->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_SETUP_SCORING);
    expect($activeRunId)->not->toBeNull();

    // 2. Simulate multiple exchange/letter slices creating alerts under the active collection run
    StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'AAPL',
        'company_name' => 'Apple Inc.',
        'exchange' => 'NASDAQ',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 88,
        'raw_setup_score' => 88,
        'heartbeat_score' => 88,
        'spike_date' => '2026-11-05',
        'price' => 225.00,
        'market_cap' => 3000000000000,
        'scan_run_id' => $activeRunId,
        'first_scan_run_id' => $activeRunId,
        'status' => 'new',
        'detected_at' => now(),
    ]);
    $coordinator->recordSetupSliceSummary($activeRunId, 10, [
        'processed' => 10,
        'matched' => 1,
        'rejected' => 9,
        'errors' => 0,
        'reasons' => ['no spike' => 9],
        'top' => [['symbol' => 'AAPL', 'setup_type' => 'heartbeat_consolidation_spike', 'score' => 88, 'status' => 'created']],
    ], 1.2);

    StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'NVDA',
        'company_name' => 'NVIDIA Corp.',
        'exchange' => 'NASDAQ',
        'setup_type' => 'early_breakout_followthrough',
        'setup_score' => 95,
        'raw_setup_score' => 95,
        'heartbeat_score' => 95,
        'spike_date' => '2026-11-05',
        'price' => 142.00,
        'market_cap' => 3500000000000,
        'scan_run_id' => $activeRunId,
        'first_scan_run_id' => $activeRunId,
        'status' => 'new',
        'detected_at' => now(),
    ]);
    $coordinator->recordSetupSliceSummary($activeRunId, 15, [
        'processed' => 15,
        'matched' => 1,
        'rejected' => 14,
        'errors' => 0,
        'reasons' => ['no spike' => 14],
        'top' => [['symbol' => 'NVDA', 'setup_type' => 'early_breakout_followthrough', 'score' => 95, 'status' => 'created']],
    ], 1.5);

    // Zero notifications must be sent while slices are still collecting
    Notification::assertNothingSent();
    expect(StockBuySetupRun::find($activeRunId)->status)->toBe('collecting');

    // 3. Finalize collection and prepare digest (as scripts/duoasset-setup-scan.sh does after the loop)
    $this->artisan('digest:prepare', ['category' => 'setup_scoring', '--sync' => true])
        ->assertSuccessful();

    $completedRun = StockBuySetupRun::find($activeRunId);
    expect($completedRun->status)->toBe('completed')
        ->and($completedRun->symbols_considered)->toBe(25)
        ->and($completedRun->matched_count)->toBe(2)
        ->and($coordinator->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_SETUP_SCORING))->toBeNull();

    // Exactly ONE SetupScoringDigestNotification sent containing both AAPL and NVDA
    Notification::assertSentToTimes($subscriber, SetupScoringDigestNotification::class, 1);
    Notification::assertSentTo($subscriber, SetupScoringDigestNotification::class, function (SetupScoringDigestNotification $notification) use ($subscriber) {
        $html = (string) $notification->toMail($subscriber)->render();

        return str_contains($html, 'AAPL') && str_contains($html, 'NVDA');
    });
});

test('intraday --no-digest earnings sweeps do not send partial emails and nightly coordinated earnings scan includes previously touched alerts in one complete digest', function () {
    Notification::fake();

    $subscriber = User::factory()->create([
        'email' => 'eps-sub@example.com',
        'notify_eps_earnings' => true,
        'email_eps_surprise_digest' => true,
    ]);

    $fake = new FakeMarketDataProvider;
    $reportDate = CarbonImmutable::today()->toDateString();

    $fake->surpriseRows = [
        [
            'symbol' => 'SHOP',
            'company_name' => 'Shopify Inc.',
            'exchange' => 'NYSE',
            'report_date' => $reportDate,
            'eps_estimated' => 0.20,
            'eps_actual' => 0.50,
            'revenue_estimated' => 2000000000,
            'revenue_actual' => 2300000000,
        ],
        [
            'symbol' => 'PLTR',
            'company_name' => 'Palantir Technologies',
            'exchange' => 'NYSE',
            'report_date' => $reportDate,
            'eps_estimated' => 0.05,
            'eps_actual' => 0.15,
            'revenue_estimated' => 700000000,
            'revenue_actual' => 820000000,
        ],
    ];

    foreach (['SHOP' => 110.0, 'PLTR' => 65.0] as $sym => $price) {
        $fake->profiles[$sym] = [
            'symbol' => $sym,
            'company_name' => $sym === 'SHOP' ? 'Shopify Inc.' : 'Palantir Technologies',
            'exchange' => 'NYSE',
            'market_cap' => 120000000000,
            'shares_outstanding' => 1000000000,
            'price' => $price,
        ];
        $fake->quotes[$sym] = [
            'symbol' => $sym,
            'price' => $price,
            'volume' => 25000000,
            'avg_volume' => 12000000,
            'daily_change_percent' => 8.5,
            'market_cap' => 120000000000,
            'shares_outstanding' => 1000000000,
            'exchange' => 'NYSE',
        ];
    }

    app()->instance(MarketDataProvider::class, $fake);

    // 1. Intraday 5-minute sweep runs with --no-digest: creates alerts for SHOP & PLTR, sends 0 notifications
    $this->artisan('earnings:scan-surprises', [
        '--from' => $reportDate,
        '--to' => $reportDate,
        '--no-digest' => true,
    ])->assertSuccessful();

    expect(EarningsAlert::count())->toBe(2);
    Notification::assertNothingSent();

    // 2. Nightly coordinated earnings scan runs across the full window (without --force)
    $this->artisan('digest:start-collection', [
        'category' => 'eps_surprise',
        '--from' => $reportDate,
        '--to' => $reportDate,
    ])->assertSuccessful();

    $masterRunId = app(ScannerDigestCoordinator::class)->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE);
    expect($masterRunId)->not->toBeNull();

    $this->artisan('earnings:scan-surprises', [
        '--from' => $reportDate,
        '--to' => $reportDate,
    ])->assertSuccessful();

    // No notification sent until digest:prepare runs
    Notification::assertNothingSent();

    $this->artisan('digest:prepare', [
        'category' => 'eps_surprise',
        '--sync' => true,
    ])->assertSuccessful();

    // Both SHOP and PLTR alerts were re-associated with the nightly master run ID and sent in 1 complete digest
    expect(EarningsAlert::where('scan_run_id', $masterRunId)->count())->toBe(2)
        ->and(EarningsScanRun::find($masterRunId)->status)->toBe(EarningsScanRun::STATUS_COMPLETED);

    Notification::assertSentToTimes($subscriber, EpsSurpriseDigestNotification::class, 1);
    Notification::assertSentTo($subscriber, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) use ($subscriber) {
        $html = (string) $notification->toMail($subscriber)->render();

        return str_contains($html, 'SHOP') && str_contains($html, 'PLTR');
    });
});
