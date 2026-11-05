<?php

use App\Jobs\PrepareScannerRunDigests;
use App\Jobs\SendUserNotificationDigest;
use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Models\EarningsScanRun;
use App\Models\NotificationDigestDelivery;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Notifications\EpsSurpriseDigestNotification;
use App\Services\Notifications\EpsSurpriseDigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('prevents duplicate logical digest deliveries when coordinator runs multiple times or workers race', function () {
    Notification::fake();

    $user = User::factory()->create(['email_eps_surprise_digest' => true]);

    $run = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    $event = EarningsEvent::create([
        'source' => 'fmp',
        'scan_run_id' => $run->id,
        'symbol' => 'AAPL',
        'company_name' => 'Apple Inc.',
        'exchange' => 'NASDAQ',
        'report_date' => '2026-11-04',
        'eps_estimated' => 1.00,
        'eps_actual' => 2.20,
        'eps_surprise' => 1.20,
        'eps_surprise_percent' => 120.0,
        'market_cap' => 3_000_000_000_000,
        'detected_at' => now(),
    ]);

    EarningsAlert::create([
        'earnings_event_id' => $event->id,
        'scan_run_id' => $run->id,
        'symbol' => 'AAPL',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 90,
        'status' => 'new',
    ]);

    // Running coordinator twice for the same run must not create duplicate outbox rows or send twice
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run->id);
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run->id);

    expect(NotificationDigestDelivery::count())->toBe(1);
    Notification::assertSentToTimes($user, EpsSurpriseDigestNotification::class, 1);

    // If a second worker picks up a duplicate job for the same deliveryId after it was sent, it exits safely
    $delivery = NotificationDigestDelivery::first();
    app()->call([new SendUserNotificationDigest($delivery->id), 'handle']);

    Notification::assertSentToTimes($user, EpsSurpriseDigestNotification::class, 1);
});

it('prevents concurrent workers from claiming a digest that is already actively processing', function () {
    Notification::fake();

    $user = User::factory()->create(['email_eps_surprise_digest' => true]);
    $run = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    $delivery = NotificationDigestDelivery::create([
        'scan_run_id' => $run->id,
        'category' => NotificationDigestDelivery::CATEGORY_EPS_SURPRISE,
        'user_id' => $user->id,
        'status' => NotificationDigestDelivery::STATUS_PROCESSING,
        'claimed_at' => now()->subSeconds(30),
        'attempts' => 1,
    ]);

    app()->call([new SendUserNotificationDigest($delivery->id), 'handle']);

    Notification::assertNothingSentTo($user);
    expect($delivery->fresh()->attempts)->toBe(1);
});

it('tracks failed deliveries with last_error and preserves watchlist snapshot across retries', function () {
    Queue::fake([SendUserNotificationDigest::class]);

    $user = User::factory()->create(['email_eps_surprise_digest' => true]);
    $provisioner = new \App\Services\Stocks\StockProvisioner(null);
    $stock = $provisioner->findOrCreate('NVDA', 'NASDAQ', 'NVIDIA Corp');
    $watchlist = Watchlist::create([
        'user_id' => $user->id,
        'name' => 'Tech',
        'is_default' => true,
    ]);
    WatchlistItem::create([
        'watchlist_id' => $watchlist->id,
        'stock_id' => $stock->id,
        'currency' => 'USD',
        'moat_level' => \App\Enums\MoatLevel::Medium->value,
    ]);

    $run = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    $event = EarningsEvent::create([
        'source' => 'fmp',
        'scan_run_id' => $run->id,
        'symbol' => 'NVDA',
        'company_name' => 'NVIDIA Corp',
        'exchange' => 'NASDAQ',
        'report_date' => '2026-11-04',
        'eps_estimated' => 1.00,
        'eps_actual' => 2.50,
        'eps_surprise' => 1.50,
        'eps_surprise_percent' => 150.0,
        'market_cap' => 2_000_000_000,
        'detected_at' => now(),
    ]);

    EarningsAlert::create([
        'earnings_event_id' => $event->id,
        'scan_run_id' => $run->id,
        'symbol' => 'NVDA',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 100,
        'status' => 'new',
    ]);

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run->id);

    $delivery = NotificationDigestDelivery::first();
    expect($delivery->watchlist_snapshot)->toBe(['NVDA']);

    // Simulate a transient transport failure on attempt #1 by mocking EpsSurpriseDigestBuilder to throw once
    $failingBuilder = Mockery::mock(EpsSurpriseDigestBuilder::class);
    $failingBuilder->shouldReceive('buildForUser')->once()->andThrow(new RuntimeException('SMTP connection timed out'));

    expect(fn () => (new SendUserNotificationDigest($delivery->id))->handle(
        $failingBuilder,
        app(\App\Services\Notifications\SetupScoringDigestBuilder::class),
        app(\App\Services\Notifications\UserWatchlistSymbolResolver::class),
    ))->toThrow(RuntimeException::class, 'SMTP connection timed out');

    $delivery->refresh();
    expect($delivery->status)->toBe(NotificationDigestDelivery::STATUS_FAILED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toContain('SMTP connection timed out')
        ->and($delivery->claimed_at)->toBeNull();

    // Even if the user modifies their watchlist before retry #2, the retry uses the captured watchlist_snapshot
    WatchlistItem::where('watchlist_id', $watchlist->id)->delete();

    Notification::fake();
    app()->call([new SendUserNotificationDigest($delivery->id), 'handle']);

    $delivery->refresh();
    expect($delivery->status)->toBe(NotificationDigestDelivery::STATUS_SENT)
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->last_error)->toBeNull()
        ->and($delivery->sent_at)->not->toBeNull();

    Notification::assertSentTo($user, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) {
        expect(array_column($notification->digest['watchlist_surprises'], 'symbol'))->toBe(['NVDA']);

        return true;
    });
});

it('does not generate digests for incomplete, failed, or empty scanner runs and never mixes results across runs', function () {
    Notification::fake();

    $user = User::factory()->create(['email_eps_surprise_digest' => true]);

    // 1. Incomplete ('running') scan run
    $runningScan = EarningsScanRun::create([
        'started_at' => now(),
        'status' => EarningsScanRun::STATUS_RUNNING,
    ]);
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $runningScan->id);
    Notification::assertNothingSent();

    // 2. Completed scan run with 0 qualifying alerts
    $emptyScan = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $emptyScan->id);
    Notification::assertNothingSent();

    // 3. Run at 09:00 vs Run at 11:00 must never mix events
    $run0900 = EarningsScanRun::create([
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHours(2)->addMinute(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);
    $event0900 = EarningsEvent::create([
        'source' => 'fmp',
        'scan_run_id' => $run0900->id,
        'symbol' => 'MORNING',
        'company_name' => 'Morning Co',
        'exchange' => 'NYSE',
        'report_date' => '2026-11-04',
        'eps_estimated' => 1.0,
        'eps_actual' => 2.5,
        'eps_surprise' => 1.5,
        'eps_surprise_percent' => 150.0,
        'market_cap' => 1_000_000_000,
        'detected_at' => now()->subHours(2),
    ]);
    EarningsAlert::create([
        'earnings_event_id' => $event0900->id,
        'scan_run_id' => $run0900->id,
        'symbol' => 'MORNING',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 70,
        'status' => 'new',
    ]);

    $run1100 = EarningsScanRun::create([
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(9),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);
    $event1100 = EarningsEvent::create([
        'source' => 'fmp',
        'scan_run_id' => $run1100->id,
        'symbol' => 'MIDDAY',
        'company_name' => 'Midday Co',
        'exchange' => 'NYSE',
        'report_date' => '2026-11-04',
        'eps_estimated' => 1.0,
        'eps_actual' => 3.0,
        'eps_surprise' => 2.0,
        'eps_surprise_percent' => 200.0,
        'market_cap' => 1_000_000_000,
        'detected_at' => now()->subMinutes(10),
    ]);
    EarningsAlert::create([
        'earnings_event_id' => $event1100->id,
        'scan_run_id' => $run1100->id,
        'symbol' => 'MIDDAY',
        'alert_type' => 'eps_surprise',
        'direction' => EarningsAlert::DIRECTION_POSITIVE,
        'score' => 85,
        'status' => 'new',
    ]);

    // Dispatch digest for the 09:00 run AFTER the 11:00 run has already persisted MIDDAY
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run0900->id);

    Notification::assertSentTo($user, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) {
        $symbols = array_column($notification->digest['market_surprises'], 'symbol');
        expect($symbols)->toBe(['MORNING'])
            ->and($symbols)->not->toContain('MIDDAY');

        return true;
    });
});

it('computes global rankings once per scanner run and personalizes efficiently across hundreds of events and multiple users without N+1 queries', function () {
    Notification::fake();

    $run = EarningsScanRun::create([
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    // Bulk-insert 300 qualifying EPS events and alerts in the completed run
    $now = now()->toDateTimeString();
    $eventsBatch = [];
    for ($i = 1; $i <= 300; $i++) {
        $isPositive = $i <= 220;
        $pct = $isPositive ? (95.0 + $i) : (-35.0 - ($i - 220));
        $eventsBatch[] = [
            'source' => 'fmp',
            'scan_run_id' => $run->id,
            'symbol' => sprintf('SYM%04d', $i),
            'company_name' => sprintf('Company %04d', $i),
            'exchange' => 'NASDAQ',
            'report_date' => '2026-11-04',
            'eps_estimated' => 1.00,
            'eps_actual' => 1.00 + ($pct / 100.0),
            'eps_surprise' => $pct / 100.0,
            'eps_surprise_percent' => $pct,
            'market_cap' => 1_000_000_000,
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('earnings_events')->insert($eventsBatch);

    $insertedEvents = EarningsEvent::where('scan_run_id', $run->id)->get();
    $alertsBatch = [];
    foreach ($insertedEvents as $ev) {
        $pct = (float) $ev->eps_surprise_percent;
        $alertsBatch[] = [
            'earnings_event_id' => $ev->id,
            'scan_run_id' => $run->id,
            'symbol' => $ev->symbol,
            'alert_type' => 'eps_surprise',
            'direction' => $pct >= 0 ? EarningsAlert::DIRECTION_POSITIVE : EarningsAlert::DIRECTION_NEGATIVE,
            'score' => $pct >= 0 ? min(120, 40 + (int) floor($pct / 10)) : 20,
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('earnings_alerts')->insert($alertsBatch);

    // Create 10 subscribed users (5 with watchlists, 5 without watchlists)
    $users = [];
    $provisioner = new \App\Services\Stocks\StockProvisioner(null);
    for ($u = 1; $u <= 10; $u++) {
        $user = User::factory()->create(['email_eps_surprise_digest' => true]);
        if ($u <= 5) {
            $sym = sprintf('SYM%04d', $u * 10);
            $stock = $provisioner->findOrCreate($sym, 'NASDAQ', "Company {$sym}");
            $wl = Watchlist::create(['user_id' => $user->id, 'name' => 'Main', 'is_default' => true]);
            WatchlistItem::create([
                'watchlist_id' => $wl->id,
                'stock_id' => $stock->id,
                'currency' => 'USD',
                'moat_level' => \App\Enums\MoatLevel::Medium->value,
            ]);
        }
        $users[] = $user;
    }

    // Build global snapshot ONCE
    $builder = app(EpsSurpriseDigestBuilder::class);
    $snapshot = $builder->buildAndStoreRunSnapshot($run->id);

    expect($snapshot['totals']['total_market_surprises'])->toBe(220)
        ->and($snapshot['totals']['total_market_misses'])->toBe(80)
        ->and($snapshot['top_60_scores'])->toHaveCount(60);

    // Personalizing for a user without a watchlist should execute 0 database queries!
    DB::enableQueryLog();
    DB::flushQueryLog();
    $emptyWatchlistDigest = $builder->buildForUser($run->id, []);
    $queriesForEmptyWatchlist = count(DB::getQueryLog());

    expect($queriesForEmptyWatchlist)->toBe(0)
        ->and($emptyWatchlistDigest['market_surprises'])->toHaveCount(50)
        ->and($emptyWatchlistDigest['market_misses'])->toHaveCount(20)
        ->and($emptyWatchlistDigest['top_scores'])->toHaveCount(60);

    // Personalizing for a user with a watchlist executes only the targeted watchlist alert lookup (<= 2 queries: alerts + eager-loaded events)
    DB::flushQueryLog();
    $watchedDigest = $builder->buildForUser($run->id, ['SYM0010', 'SYM0250']);
    $queriesForWatchedUser = count(DB::getQueryLog());

    expect($queriesForWatchedUser)->toBeLessThanOrEqual(2)
        ->and(array_column($watchedDigest['watchlist_surprises'], 'symbol'))->toBe(['SYM0010'])
        ->and(array_column($watchedDigest['watchlist_misses'], 'symbol'))->toBe(['SYM0250'])
        ->and($watchedDigest['market_surprises'])->toHaveCount(50)
        ->and($watchedDigest['market_misses'])->toHaveCount(20)
        ->and($watchedDigest['top_scores'])->toHaveCount(60);

    DB::disableQueryLog();

    // Full end-to-end coordinator run sends 1 digest to each of the 10 subscribed users
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run->id);

    foreach ($users as $user) {
        Notification::assertSentToTimes($user, EpsSurpriseDigestNotification::class, 1);
    }
});
