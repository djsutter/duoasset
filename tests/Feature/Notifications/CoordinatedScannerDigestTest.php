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

test('real end-to-end stocks:scan-buy-setups pipeline delivers consolidated digest across slices, survives cache clear mid-run, and does not skip when re-run with unchanged scores', function () {
    Notification::fake();

    // Seed Admin1 and Admin2 via UserSeeder and verify both are eligible for setup_scoring & eps_surprise digests
    $this->seed(\Database\Seeders\UserSeeder::class);

    $admin1 = User::where('email', 'dsutter@qualivera.com')->firstOrFail();
    $admin2 = User::where('email', 'joe@7pro.ca')->firstOrFail();

    expect($admin1->isSubscribedToDigest(NotificationDigestDelivery::CATEGORY_SETUP_SCORING))->toBeTrue()
        ->and($admin1->isSubscribedToDigest(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE))->toBeTrue()
        ->and($admin2->isSubscribedToDigest(NotificationDigestDelivery::CATEGORY_SETUP_SCORING))->toBeTrue()
        ->and($admin2->isSubscribedToDigest(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE))->toBeTrue();

    // Attach AAPL to Admin2's watchlist so AAPL is prioritized in Admin2's Watchlist section
    $watchlist = \App\Models\Watchlist::create([
        'user_id' => $admin2->id,
        'name' => 'Admin2 Core Watchlist',
        'is_default' => true,
    ]);
    $aaplStock = (new \App\Services\Stocks\StockProvisioner(null))->findOrCreate('AAPL', 'NASDAQ', 'Apple Inc.');
    \App\Models\WatchlistItem::create([
        'watchlist_id' => $watchlist->id,
        'stock_id' => $aaplStock->id,
        'currency' => 'USD',
        'moat_level' => \App\Enums\MoatLevel::Medium->value,
    ]);

    // Build realistic 256-bar daily series with a tight consolidation base followed by a 104-week volume spike
    $baseDate = CarbonImmutable::today()->subDays(260);
    $bars = [];
    for ($i = 0; $i < 250; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 10.0,
            'high' => 10.2,
            'low' => 9.8,
            'close' => 10.0,
            'adj_close' => 10.0,
            'volume' => $i >= 220 ? 50_000 : 100_000,
        ];
    }
    $bars[] = [
        'date' => $baseDate->addDays(250)->toDateString(),
        'open' => 10.0,
        'high' => 11.2,
        'low' => 9.9,
        'close' => 11.0,
        'adj_close' => 11.0,
        'volume' => 500_000,
    ];
    for ($i = 1; $i <= 5; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays(250 + $i)->toDateString(),
            'open' => 11.0,
            'high' => 11.2,
            'low' => 10.8,
            'close' => 11.0,
            'adj_close' => 11.0,
            'volume' => 100_000,
        ];
    }

    $fake = new FakeMarketDataProvider;
    $fake->screenerRows = [
        [
            'symbol' => 'AAPL',
            'company_name' => 'Apple Inc.',
            'exchange' => 'NASDAQ',
            'market_cap' => 110_000_000,
            'price' => 11.00,
            'shares_outstanding' => 10_000_000,
            'is_actively_trading' => true,
            'is_etf' => false,
            'is_fund' => false,
        ],
        [
            'symbol' => 'NVDA',
            'company_name' => 'NVIDIA Corp.',
            'exchange' => 'NASDAQ',
            'market_cap' => 110_000_000,
            'price' => 11.00,
            'shares_outstanding' => 10_000_000,
            'is_actively_trading' => true,
            'is_etf' => false,
            'is_fund' => false,
        ],
    ];

    foreach (['AAPL' => 'Apple Inc.', 'NVDA' => 'NVIDIA Corp.', 'SPY' => 'SPDR S&P 500'] as $sym => $name) {
        $fake->profiles[$sym] = [
            'symbol' => $sym,
            'company_name' => $name,
            'exchange' => 'NASDAQ',
            'price' => 11.00,
            'shares_outstanding' => 10_000_000,
            'float_shares' => 1_000_000,
            'free_float' => 10.0,
            'market_cap' => 110_000_000,
            'currency' => 'USD',
            'is_actively_trading' => true,
            'is_etf' => $sym === 'SPY',
            'is_fund' => false,
        ];
        $fake->dailyBars[$sym] = $bars;
        $fake->incomeStatements[$sym] = [
            ['date' => '2026-09-30', 'revenue' => 45_000_000, 'net_income' => 8_000_000, 'eps' => 0.80, 'operating_income' => 10_000_000, 'fiscal_year' => 2026, 'period' => 'Q3', 'reported_currency' => 'USD'],
            ['date' => '2026-06-30', 'revenue' => 35_000_000, 'net_income' => 6_000_000, 'eps' => 0.60, 'operating_income' => 7_500_000, 'fiscal_year' => 2026, 'period' => 'Q2', 'reported_currency' => 'USD'],
            ['date' => '2026-03-31', 'revenue' => 28_000_000, 'net_income' => 4_500_000, 'eps' => 0.45, 'operating_income' => 5_500_000, 'fiscal_year' => 2026, 'period' => 'Q1', 'reported_currency' => 'USD'],
            ['date' => '2025-12-31', 'revenue' => 22_000_000, 'net_income' => 3_500_000, 'eps' => 0.35, 'operating_income' => 4_200_000, 'fiscal_year' => 2025, 'period' => 'Q4', 'reported_currency' => 'USD'],
            ['date' => '2025-09-30', 'revenue' => 20_000_000, 'net_income' => 2_500_000, 'eps' => 0.25, 'operating_income' => 3_000_000, 'fiscal_year' => 2025, 'period' => 'Q3', 'reported_currency' => 'USD'],
            ['date' => '2025-06-30', 'revenue' => 19_000_000, 'net_income' => 2_200_000, 'eps' => 0.22, 'operating_income' => 2_700_000, 'fiscal_year' => 2025, 'period' => 'Q2', 'reported_currency' => 'USD'],
            ['date' => '2025-03-31', 'revenue' => 18_000_000, 'net_income' => 2_000_000, 'eps' => 0.20, 'operating_income' => 2_400_000, 'fiscal_year' => 2025, 'period' => 'Q1', 'reported_currency' => 'USD'],
            ['date' => '2024-12-31', 'revenue' => 17_000_000, 'net_income' => 1_800_000, 'eps' => 0.18, 'operating_income' => 2_100_000, 'fiscal_year' => 2024, 'period' => 'Q4', 'reported_currency' => 'USD'],
        ];
        $fake->balanceSheets[$sym] = [
            [
                'date' => '2026-09-30',
                'stockholders_equity' => 125_000_000,
                'fiscal_year' => 2026,
                'period' => 'Q3',
                'reported_currency' => 'USD',
            ],
        ];
    }

    app()->instance(MarketDataProvider::class, $fake);

    // --- NIGHT 1: Full coordinated setup scan across slices ---
    $this->artisan('digest:start-collection', ['category' => 'setup_scoring'])->assertSuccessful();
    $run1Id = app(ScannerDigestCoordinator::class)->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_SETUP_SCORING);
    expect($run1Id)->not->toBeNull();

    // Slice 1: evaluate AAPL
    $this->artisan('stocks:scan-buy-setups', [
        '--symbol' => ['AAPL'],
        '--sync' => true,
    ])->assertSuccessful();

    // Simulate cache clear mid-run (e.g. ./clearcache) and verify coordinator recovers active run from Setting / DB
    \Illuminate\Support\Facades\Cache::flush();
    expect(app(ScannerDigestCoordinator::class)->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_SETUP_SCORING))->toBe($run1Id);

    // Slice 2: evaluate NVDA
    $this->artisan('stocks:scan-buy-setups', [
        '--symbol' => ['NVDA'],
        '--sync' => true,
    ])->assertSuccessful();

    Notification::assertNothingSent();

    $this->artisan('digest:prepare', ['category' => 'setup_scoring', '--sync' => true])->assertSuccessful();

    Notification::assertSentToTimes($admin1, SetupScoringDigestNotification::class, 1);
    Notification::assertSentToTimes($admin2, SetupScoringDigestNotification::class, 1);

    // --- NIGHT 2: Re-running coordinated setup scan when scores are unchanged (score_delta === 0) ---
    $this->artisan('digest:start-collection', ['category' => 'setup_scoring'])->assertSuccessful();
    $run2Id = app(ScannerDigestCoordinator::class)->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_SETUP_SCORING);
    expect($run2Id)->not->toBe($run1Id);

    $this->artisan('stocks:scan-buy-setups', [
        '--symbol' => ['AAPL'],
        '--sync' => true,
    ])->assertSuccessful();

    $this->artisan('stocks:scan-buy-setups', [
        '--symbol' => ['NVDA'],
        '--sync' => true,
    ])->assertSuccessful();

    // Both AAPL and NVDA now have score_delta === 0 on Run #2
    expect(StockBuySetupAlert::where('scan_run_id', $run2Id)->where('symbol', 'AAPL')->value('score_delta'))->toBe(0)
        ->and(StockBuySetupAlert::where('scan_run_id', $run2Id)->where('symbol', 'NVDA')->value('score_delta'))->toBe(0);

    $this->artisan('digest:prepare', ['category' => 'setup_scoring', '--sync' => true])->assertSuccessful();

    // Run #2 MUST still deliver 1 digest to Admin1 and 1 digest to Admin2 (never skipped_empty!)
    $admin2DeliveryRun2 = NotificationDigestDelivery::where('scan_run_id', $run2Id)
        ->where('user_id', $admin2->id)
        ->first();
    expect($admin2DeliveryRun2)->not->toBeNull()
        ->and($admin2DeliveryRun2->status)->toBe(NotificationDigestDelivery::STATUS_SENT);

    Notification::assertSentToTimes($admin1, SetupScoringDigestNotification::class, 2);
    Notification::assertSentToTimes($admin2, SetupScoringDigestNotification::class, 2);

    Notification::assertSentTo($admin2, SetupScoringDigestNotification::class, function (SetupScoringDigestNotification $notification) use ($admin2, $run2Id) {
        if (($notification->digest['scan_run_id'] ?? null) !== $run2Id) {
            return false;
        }

        // AAPL is prioritized in Admin2's Watchlist section, while NVDA is in Other Setup Scoring Notices
        $watchedUnchangedSymbols = array_column($notification->digest['watchlist_unchanged'] ?? [], 'symbol');
        $otherSymbols = array_column($notification->digest['other_notices'] ?? [], 'symbol');
        $html = (string) $notification->toMail($admin2)->render();

        return in_array('AAPL', $watchedUnchangedSymbols, true)
            && in_array('NVDA', $otherSymbols, true)
            && ! in_array('AAPL', $otherSymbols, true)
            && str_contains($html, 'AAPL')
            && str_contains($html, 'NVDA');
    });

    // --- NIGHT 3: After adding/enabling new scoring component or adjusting weights, scores are all different on next run ---
    $priorAaplScore = (int) StockBuySetupAlert::where('symbol', 'AAPL')->value('setup_score');
    $priorNvdaScore = (int) StockBuySetupAlert::where('symbol', 'NVDA')->value('setup_score');

    // Simulate pre-Book-Value state on AAPL (price_to_book_ratio = null) AND adjusted weights in BuySetupConfigService
    StockBuySetupAlert::where('symbol', 'AAPL')->update([
        'price_to_book_ratio' => null,
        'book_value_per_share' => null,
    ]);

    $configService = app(\App\Services\Stocks\BuySetupConfigService::class);
    $cfg = $configService->getConfig();
    $cfg['setup_types']['heartbeat_consolidation_spike']['score_weights']['book_value']['weight'] = 25;
    $cfg['setup_types']['heartbeat_consolidation_spike']['BOOK_VALUE_WEIGHT'] = 25;
    $cfg['setup_types']['heartbeat_consolidation_spike']['score_weights']['atr_contraction']['weight'] = 0;
    $configService->saveConfig($cfg);

    $this->artisan('digest:start-collection', ['category' => 'setup_scoring'])->assertSuccessful();
    $run3Id = app(ScannerDigestCoordinator::class)->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_SETUP_SCORING);
    expect($run3Id)->not->toBe($run2Id);

    $this->artisan('stocks:scan-buy-setups', [
        '--symbol' => ['AAPL', 'NVDA'],
        '--sync' => true,
    ])->assertSuccessful();

    $aaplAfterRun3 = StockBuySetupAlert::where('scan_run_id', $run3Id)->where('symbol', 'AAPL')->firstOrFail();
    $nvdaAfterRun3 = StockBuySetupAlert::where('scan_run_id', $run3Id)->where('symbol', 'NVDA')->firstOrFail();

    expect((int) $aaplAfterRun3->previous_setup_score)->toBe($priorAaplScore)
        ->and((int) $aaplAfterRun3->setup_score)->not->toBe($priorAaplScore)
        ->and((int) $aaplAfterRun3->score_delta)->toBe((int) $aaplAfterRun3->setup_score - $priorAaplScore)
        ->and((int) $nvdaAfterRun3->previous_setup_score)->toBe($priorNvdaScore)
        ->and((int) $nvdaAfterRun3->setup_score)->not->toBe($priorNvdaScore)
        ->and((int) $nvdaAfterRun3->score_delta)->toBe((int) $nvdaAfterRun3->setup_score - $priorNvdaScore);

    $this->artisan('digest:prepare', ['category' => 'setup_scoring', '--sync' => true])->assertSuccessful();

    Notification::assertSentToTimes($admin1, SetupScoringDigestNotification::class, 3);
    Notification::assertSentToTimes($admin2, SetupScoringDigestNotification::class, 3);

    Notification::assertSentTo($admin2, SetupScoringDigestNotification::class, function (SetupScoringDigestNotification $notification) use ($run3Id) {
        if (($notification->digest['scan_run_id'] ?? null) !== $run3Id) {
            return false;
        }

        $improvedSymbols = array_column($notification->digest['watchlist_improvements'] ?? [], 'symbol');
        $otherSymbols = array_column($notification->digest['other_notices'] ?? [], 'symbol');

        return in_array('AAPL', $improvedSymbols, true)
            && in_array('NVDA', $otherSymbols, true)
            && empty($notification->digest['watchlist_unchanged']);
    });
});

test('intraday --no-digest earnings sweep running after nightly digest:prepare does not steal scan_run_id from queued SendUserNotificationDigest', function () {
    Notification::fake();
    \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SendUserNotificationDigest::class]);

    $subscriber = User::factory()->create([
        'email' => 'queued-eps@example.com',
        'notify_eps_earnings' => true,
        'email_eps_surprise_digest' => true,
    ]);

    $watchlist = \App\Models\Watchlist::create([
        'user_id' => $subscriber->id,
        'name' => 'Earnings Watchlist',
        'is_default' => true,
    ]);
    $shopStock = (new \App\Services\Stocks\StockProvisioner(null))->findOrCreate('SHOP', 'NYSE', 'Shopify Inc.');
    \App\Models\WatchlistItem::create([
        'watchlist_id' => $watchlist->id,
        'stock_id' => $shopStock->id,
        'currency' => 'USD',
        'moat_level' => \App\Enums\MoatLevel::Medium->value,
    ]);

    $reportDate = CarbonImmutable::today()->toDateString();
    $fake = new FakeMarketDataProvider;
    $fake->surpriseRows = [
        [
            'symbol' => 'SHOP',
            'company_name' => 'Shopify Inc.',
            'exchange' => 'NYSE',
            'report_date' => $reportDate,
            'eps_estimated' => 0.20,
            'eps_actual' => 0.55,
            'revenue_estimated' => 2000000000,
            'revenue_actual' => 2400000000,
        ],
    ];
    $fake->profiles['SHOP'] = [
        'symbol' => 'SHOP',
        'company_name' => 'Shopify Inc.',
        'exchange' => 'NYSE',
        'market_cap' => 120000000000,
        'shares_outstanding' => 1000000000,
        'price' => 110.0,
    ];
    $fake->quotes['SHOP'] = [
        'symbol' => 'SHOP',
        'price' => 110.0,
        'volume' => 25000000,
        'avg_volume' => 12000000,
        'daily_change_percent' => 8.5,
        'market_cap' => 120000000000,
        'shares_outstanding' => 1000000000,
        'exchange' => 'NYSE',
    ];
    app()->instance(MarketDataProvider::class, $fake);

    // 1. Nightly coordinated earnings scan completes and queues SendUserNotificationDigest
    $this->artisan('digest:start-collection', [
        'category' => 'eps_surprise',
        '--from' => $reportDate,
        '--to' => $reportDate,
    ])->assertSuccessful();

    $nightlyRunId = app(ScannerDigestCoordinator::class)->activeCollectionRunId(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE);

    $this->artisan('earnings:scan-surprises', [
        '--from' => $reportDate,
        '--to' => $reportDate,
    ])->assertSuccessful();

    $this->artisan('digest:prepare', [
        'category' => 'eps_surprise',
    ])->assertSuccessful();

    $delivery = NotificationDigestDelivery::where('scan_run_id', $nightlyRunId)
        ->where('user_id', $subscriber->id)
        ->firstOrFail();

    // 2. Before queue worker runs SendUserNotificationDigest, an intraday --no-digest sweep executes
    $this->artisan('earnings:scan-surprises', [
        '--from' => $reportDate,
        '--to' => $reportDate,
        '--no-digest' => true,
    ])->assertSuccessful();

    // The SHOP alert must still retain the nightly digest's scan_run_id
    expect(EarningsAlert::where('symbol', 'SHOP')->value('scan_run_id'))->toBe($nightlyRunId);

    // 3. Queue worker now executes SendUserNotificationDigest
    app()->call([new \App\Jobs\SendUserNotificationDigest($delivery->id), 'handle']);

    $delivery->refresh();
    expect($delivery->status)->toBe(NotificationDigestDelivery::STATUS_SENT);

    Notification::assertSentToTimes($subscriber, EpsSurpriseDigestNotification::class, 1);
    Notification::assertSentTo($subscriber, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) {
        return array_column($notification->digest['watchlist_surprises'], 'symbol') === ['SHOP'];
    });
});
