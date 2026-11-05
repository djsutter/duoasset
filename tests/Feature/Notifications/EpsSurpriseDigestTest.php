<?php

use App\Jobs\PrepareScannerRunDigests;
use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Models\EarningsScanRun;
use App\Models\NotificationDigestDelivery;
use App\Models\Stock;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Notifications\EarningsSurpriseDetected;
use App\Notifications\EpsSurpriseDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function seedWatchlistForUser(User $user, array $symbols): Watchlist
{
    $watchlist = Watchlist::create([
        'user_id' => $user->id,
        'name' => 'Core Watchlist',
        'is_default' => true,
    ]);

    $provisioner = new \App\Services\Stocks\StockProvisioner(null);

    foreach ($symbols as $symbol) {
        $stock = $provisioner->findOrCreate(strtoupper($symbol), 'NASDAQ', strtoupper($symbol).' Corp');

        WatchlistItem::firstOrCreate([
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
        ], [
            'currency' => 'USD',
            'moat_level' => \App\Enums\MoatLevel::Medium->value,
        ]);
    }

    return $watchlist;
}

function createRunEarningsAlert(
    int $scanRunId,
    string $symbol,
    float $epsEstimated,
    float $epsActual,
    float $surprisePct,
    string $direction,
    int $score,
    string $reportDate = '2026-11-04',
): EarningsAlert {
    $event = EarningsEvent::create([
        'source' => 'fmp',
        'scan_run_id' => $scanRunId,
        'symbol' => $symbol,
        'company_name' => "{$symbol} Holdings Inc.",
        'exchange' => 'NASDAQ',
        'report_date' => $reportDate,
        'eps_estimated' => $epsEstimated,
        'eps_actual' => $epsActual,
        'eps_surprise' => $epsActual - $epsEstimated,
        'eps_surprise_percent' => $surprisePct,
        'market_cap' => 2_000_000_000,
        'detected_at' => now(),
    ]);

    return EarningsAlert::create([
        'earnings_event_id' => $event->id,
        'scan_run_id' => $scanRunId,
        'symbol' => $symbol,
        'alert_type' => 'eps_surprise',
        'direction' => $direction,
        'score' => $score,
        'status' => 'new',
    ]);
}

it('sends exactly one EPS digest email per subscribed user per completed scanner run and never sends individual event emails', function () {
    Notification::fake();

    config([
        'market_data.provider' => 'fmp',
        'market_data.fmp.api_key' => 'test-key',
        'market_data.fmp.base_url' => 'https://test.local/stable',
        'market_data.earnings_scanner.enabled' => true,
        'market_data.earnings_scanner.min_market_cap' => 100_000_000,
        'market_data.earnings_scanner.positive_threshold' => 90,
        'market_data.earnings_scanner.negative_threshold' => -30,
        'market_data.earnings_scanner.exchanges' => ['NYSE', 'NASDAQ'],
    ]);

    $subscriber = User::factory()->create(['email_eps_surprise_digest' => true]);
    $unsubscribed = User::factory()->create(['email_eps_surprise_digest' => false]);
    seedWatchlistForUser($subscriber, ['NVDA']);

    $surprises = [];
    $profiles = [];
    $quotes = [];

    foreach (['NVDA' => 250.0, 'AAPL' => 180.0, 'MSFT' => 140.0, 'AMZN' => 120.0] as $sym => $pct) {
        $surprises[] = [
            'symbol' => $sym,
            'date' => now()->toDateString(),
            'epsEstimated' => 1.00,
            'epsActual' => 1.00 + ($pct / 100.0),
            'surprisePercentage' => $pct,
        ];
        $profiles[$sym] = [
            'symbol' => $sym,
            'companyName' => "{$sym} Corp",
            'exchangeShortName' => 'NASDAQ',
            'mktCap' => 5_000_000_000,
            'currency' => 'USD',
        ];
        $quotes[$sym] = [
            'symbol' => $sym,
            'price' => 100.0,
            'marketCap' => 5_000_000_000,
            'exchange' => 'NASDAQ',
            'name' => "{$sym} Corp",
        ];
    }

    Http::fake(function ($request) use ($surprises, $profiles, $quotes) {
        $url = (string) $request->url();
        if (str_contains($url, '/earnings-surprises') || str_contains($url, '/earnings-calendar')) {
            return Http::response($surprises, 200);
        }
        if (str_contains($url, '/profile')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
            $sym = $q['symbol'] ?? null;

            return Http::response($sym && isset($profiles[$sym]) ? [$profiles[$sym]] : [], 200);
        }
        if (str_contains($url, '/quote')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
            $sym = $q['symbol'] ?? null;

            return Http::response($sym && isset($quotes[$sym]) ? [$quotes[$sym]] : [], 200);
        }

        return Http::response([], 200);
    });

    $this->artisan('earnings:scan-surprises')->assertSuccessful();

    // Never send individual per-event emails
    Notification::assertNotSentTo($subscriber, EarningsSurpriseDetected::class);

    // Exactly one EPS Surprise Digest sent to subscriber, zero to unsubscribed user
    Notification::assertSentToTimes($subscriber, EpsSurpriseDigestNotification::class, 1);
    Notification::assertNotSentTo($unsubscribed, EpsSurpriseDigestNotification::class);
});

it('prioritizes all qualifying watchlist surprises and misses without applying the 50/20 market-wide caps to watchlist sections', function () {
    Notification::fake();

    $user = User::factory()->create(['email_eps_surprise_digest' => true]);

    // Watchlist has 55 positive surprises and 25 negative misses
    $watchedBeats = [];
    $watchedMisses = [];
    for ($i = 1; $i <= 55; $i++) {
        $watchedBeats[] = sprintf('WBEAT%02d', $i);
    }
    for ($i = 1; $i <= 25; $i++) {
        $watchedMisses[] = sprintf('WMISS%02d', $i);
    }
    seedWatchlistForUser($user, array_merge($watchedBeats, $watchedMisses));

    $run = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    foreach ($watchedBeats as $idx => $sym) {
        $pct = 100.0 + ($idx * 2);
        createRunEarningsAlert($run->id, $sym, 1.0, 1.0 + ($pct / 100), $pct, EarningsAlert::DIRECTION_POSITIVE, 60);
    }

    foreach ($watchedMisses as $idx => $sym) {
        $pct = -35.0 - ($idx * 2);
        createRunEarningsAlert($run->id, $sym, 1.0, 1.0 + ($pct / 100), $pct, EarningsAlert::DIRECTION_NEGATIVE, 40);
    }

    // Also add 65 non-watchlist positive surprises and 30 non-watchlist negative misses
    for ($i = 1; $i <= 65; $i++) {
        $sym = sprintf('MKTBEAT%02d', $i);
        $pct = 95.0 + $i;
        createRunEarningsAlert($run->id, $sym, 1.0, 1.0 + ($pct / 100), $pct, EarningsAlert::DIRECTION_POSITIVE, 50);
    }

    for ($i = 1; $i <= 30; $i++) {
        $sym = sprintf('MKTMISS%02d', $i);
        $pct = -32.0 - $i;
        createRunEarningsAlert($run->id, $sym, 1.0, 1.0 + ($pct / 100), $pct, EarningsAlert::DIRECTION_NEGATIVE, 30);
    }

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run->id);

    Notification::assertSentTo($user, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) use ($user) {
        $digest = $notification->digest;

        // All 55 watchlist beats and all 25 watchlist misses must be included
        expect($digest['watchlist_surprises'])->toHaveCount(55);
        expect($digest['watchlist_misses'])->toHaveCount(25);

        // Watchlist beats sorted by strongest positive surprise first
        expect($digest['watchlist_surprises'][0]['symbol'])->toBe('WBEAT55');
        expect($digest['watchlist_surprises'][54]['symbol'])->toBe('WBEAT01');

        // Watchlist misses sorted by most severe negative surprise first
        expect($digest['watchlist_misses'][0]['symbol'])->toBe('WMISS25');
        expect($digest['watchlist_misses'][24]['symbol'])->toBe('WMISS01');

        // Market-wide positive surprises capped at 50 and exclude watchlist symbols
        expect($digest['market_surprises'])->toHaveCount(50);
        $marketSurpriseSymbols = array_column($digest['market_surprises'], 'symbol');
        expect(array_intersect($marketSurpriseSymbols, array_column($digest['watchlist_surprises'], 'symbol')))->toBeEmpty();

        // Market-wide negative misses capped at 20 and exclude watchlist symbols
        expect($digest['market_misses'])->toHaveCount(20);
        $marketMissSymbols = array_column($digest['market_misses'], 'symbol');
        expect(array_intersect($marketMissSymbols, array_column($digest['watchlist_misses'], 'symbol')))->toBeEmpty();

        // Top 60 EPS scores capped at 60
        expect($digest['top_scores'])->toHaveCount(60);

        // Verify section ordering in rendered HTML & text
        $mail = $notification->toMail($user);
        $html = view($mail->view['html'], $notification->buildViewData($user))->render();

        $posA = strpos($html, '⭐ Your Watchlist — EPS Surprises');
        $posB = strpos($html, '⭐ Your Watchlist — EPS Misses');
        $posC = strpos($html, '📈 Top 50 EPS Surprises — Market Wide');
        $posD = strpos($html, '📉 Bottom 20 EPS Misses — Market Wide');
        $posE = strpos($html, '🏆 Top EPS Scoring Opportunities');

        expect($posA)->toBeLessThan($posB)
            ->and($posB)->toBeLessThan($posC)
            ->and($posC)->toBeLessThan($posD)
            ->and($posD)->toBeLessThan($posE);

        return true;
    });
});

it('builds a global Top 60 EPS Score leaderboard where watchlist items compete on global rank and show a star indicator', function () {
    Notification::fake();

    $user = User::factory()->create(['email_eps_surprise_digest' => true]);
    // Watchrank3 will rank #3 overall (score 115); Watchrank95 will rank #75 overall (score 40)
    seedWatchlistForUser($user, ['WATCHRANK3', 'WATCHRANK75']);

    $run = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    // Rank #1 non-watchlist (score 120, +400%)
    createRunEarningsAlert($run->id, 'NONWATCH1', 1.0, 5.0, 400.0, EarningsAlert::DIRECTION_POSITIVE, 120);
    // Rank #2 non-watchlist (score 118, +350%)
    createRunEarningsAlert($run->id, 'NONWATCH2', 1.0, 4.5, 350.0, EarningsAlert::DIRECTION_POSITIVE, 118);
    // Rank #3 watched (score 115, +320%)
    createRunEarningsAlert($run->id, 'WATCHRANK3', 1.0, 4.2, 320.0, EarningsAlert::DIRECTION_POSITIVE, 115);

    // Ranks #4..#74 non-watchlist (scores 110 down to 45)
    for ($i = 4; $i <= 74; $i++) {
        $score = 114 - (int) floor($i / 2);
        createRunEarningsAlert($run->id, sprintf('MKT%03d', $i), 1.0, 2.5, 150.0 - ($i * 0.5), EarningsAlert::DIRECTION_POSITIVE, $score);
    }

    // Rank #75 watched (score 40, +95%) — must NOT displace higher-scoring non-watchlist stocks in Top 60
    createRunEarningsAlert($run->id, 'WATCHRANK75', 1.0, 1.95, 95.0, EarningsAlert::DIRECTION_POSITIVE, 40);

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run->id);

    Notification::assertSentTo($user, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) {
        $topScores = $notification->digest['top_scores'];

        expect($topScores)->toHaveCount(60);
        expect($topScores[0]['symbol'])->toBe('NONWATCH1')
            ->and($topScores[0]['rank'])->toBe(1)
            ->and($topScores[0]['is_watched'])->toBeFalse();

        expect($topScores[1]['symbol'])->toBe('NONWATCH2')
            ->and($topScores[1]['rank'])->toBe(2)
            ->and($topScores[1]['is_watched'])->toBeFalse();

        expect($topScores[2]['symbol'])->toBe('WATCHRANK3')
            ->and($topScores[2]['rank'])->toBe(3)
            ->and($topScores[2]['is_watched'])->toBeTrue();

        $topScoreSymbols = array_column($topScores, 'symbol');
        expect($topScoreSymbols)->not->toContain('WATCHRANK75');

        // However, WATCHRANK75 MUST still appear in Section A (Watchlist Surprises)
        $watchlistSurpriseSymbols = array_column($notification->digest['watchlist_surprises'], 'symbol');
        expect($watchlistSurpriseSymbols)->toContain('WATCHRANK3')
            ->and($watchlistSurpriseSymbols)->toContain('WATCHRANK75');

        return true;
    });
});

it('delivers market-wide rankings to users without watchlists and omits empty watchlist sections', function () {
    Notification::fake();

    $userWithNoWatchlist = User::factory()->create(['email_eps_surprise_digest' => true]);
    $userWithWatchlist = User::factory()->create(['email_eps_surprise_digest' => true]);
    seedWatchlistForUser($userWithWatchlist, ['NVDA']);

    $run = EarningsScanRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    createRunEarningsAlert($run->id, 'NVDA', 1.0, 3.0, 200.0, EarningsAlert::DIRECTION_POSITIVE, 100);
    createRunEarningsAlert($run->id, 'AMD', 1.0, 2.5, 150.0, EarningsAlert::DIRECTION_POSITIVE, 85);
    createRunEarningsAlert($run->id, 'INTC', 1.0, 0.4, -60.0, EarningsAlert::DIRECTION_NEGATIVE, 40);

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run->id);

    Notification::assertSentTo($userWithNoWatchlist, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) use ($userWithNoWatchlist) {
        expect($notification->digest['has_watchlist'])->toBeFalse();
        expect($notification->digest['watchlist_surprises'])->toBeEmpty();
        expect($notification->digest['watchlist_misses'])->toBeEmpty();
        // Both NVDA and AMD appear in market-wide surprises for the user without a watchlist
        expect(array_column($notification->digest['market_surprises'], 'symbol'))->toBe(['NVDA', 'AMD']);
        expect(array_column($notification->digest['market_misses'], 'symbol'))->toBe(['INTC']);

        $viewData = $notification->buildViewData($userWithNoWatchlist);
        $html = view('emails.digests.eps-surprise-html', $viewData)->render();
        $text = view('emails.digests.eps-surprise-text', $viewData)->render();

        expect($html)->not->toContain('⭐ Your Watchlist — EPS Surprises')
            ->and($html)->not->toContain('⭐ Your Watchlist — EPS Misses')
            ->and($html)->toContain('📈 Top 50 EPS Surprises — Market Wide')
            ->and($html)->toContain('/stock/nvda')
            ->and($text)->not->toContain('⭐ Your Watchlist — EPS Surprises');

        return true;
    });

    // Meanwhile, the user who watches NVDA sees NVDA in Watchlist Surprises and only AMD in Market Surprises
    Notification::assertSentTo($userWithWatchlist, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) {
        expect($notification->digest['has_watchlist'])->toBeTrue();
        expect(array_column($notification->digest['watchlist_surprises'], 'symbol'))->toBe(['NVDA']);
        expect(array_column($notification->digest['market_surprises'], 'symbol'))->toBe(['AMD']);

        return true;
    });
});

it('consolidates duplicate reporting periods for the same symbol in a single run and creates separate digests only for new runs', function () {
    Notification::fake();

    $user = User::factory()->create(['email_eps_surprise_digest' => true]);

    $run1 = EarningsScanRun::create([
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(55),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);

    // Two reporting dates for DUPSYM in the same logical run
    createRunEarningsAlert($run1->id, 'DUPSYM', 1.0, 2.2, 120.0, EarningsAlert::DIRECTION_POSITIVE, 60, '2026-11-01');
    createRunEarningsAlert($run1->id, 'DUPSYM', 1.0, 2.8, 180.0, EarningsAlert::DIRECTION_POSITIVE, 80, '2026-11-04');

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run1->id);

    Notification::assertSentToTimes($user, EpsSurpriseDigestNotification::class, 1);
    Notification::assertSentTo($user, EpsSurpriseDigestNotification::class, function (EpsSurpriseDigestNotification $notification) {
        expect($notification->digest['market_surprises'])->toHaveCount(1);
        expect($notification->digest['market_surprises'][0]['symbol'])->toBe('DUPSYM');
        expect($notification->digest['market_surprises'][0]['eps_surprise_percent'])->toBe(180.0);

        return true;
    });

    // A separate logical run (#2) with a new event creates a separate digest
    $run2 = EarningsScanRun::create([
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'status' => EarningsScanRun::STATUS_COMPLETED,
    ]);
    createRunEarningsAlert($run2->id, 'NEWBEAT', 1.0, 3.0, 200.0, EarningsAlert::DIRECTION_POSITIVE, 90, '2026-11-04');

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_EPS_SURPRISE, $run2->id);

    Notification::assertSentToTimes($user, EpsSurpriseDigestNotification::class, 2);
});
