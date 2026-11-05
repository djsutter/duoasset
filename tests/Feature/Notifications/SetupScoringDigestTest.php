<?php

use App\Jobs\PrepareScannerRunDigests;
use App\Models\NotificationDigestDelivery;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Notifications\SetupScoringDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'market_data.buy_setup_scanner.notify_min_setup_score' => 50,
    ]);
});

function attachSymbolsToUserWatchlist(User $user, array $symbols): void
{
    $watchlist = Watchlist::firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Setup Watchlist'],
        ['is_default' => true],
    );

    $provisioner = new \App\Services\Stocks\StockProvisioner(null);

    foreach ($symbols as $symbol) {
        $stock = $provisioner->findOrCreate(strtoupper($symbol), 'NASDAQ', strtoupper($symbol).' Inc.');

        WatchlistItem::firstOrCreate([
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
        ], [
            'currency' => 'USD',
            'moat_level' => \App\Enums\MoatLevel::Medium->value,
        ]);
    }
}

function createSetupAlertForRun(
    int $scanRunId,
    string $symbol,
    int $currentScore,
    ?int $previousScore,
    string $setupType = 'heartbeat_consolidation_spike',
    string $spikeDate = '2026-11-03',
): StockBuySetupAlert {
    return StockBuySetupAlert::create([
        'source' => 'fmp',
        'scan_run_id' => $scanRunId,
        'first_scan_run_id' => $previousScore === null ? $scanRunId : ($scanRunId - 1),
        'symbol' => $symbol,
        'company_name' => "{$symbol} Technologies",
        'exchange' => 'NASDAQ',
        'setup_type' => $setupType,
        'setup_score' => $currentScore,
        'previous_setup_score' => $previousScore,
        'score_delta' => $previousScore !== null ? ($currentScore - $previousScore) : null,
        'raw_setup_score' => $currentScore,
        'heartbeat_score' => $currentScore,
        'spike_date' => $spikeDate,
        'status' => 'new',
        'detected_at' => now(),
    ]);
}

it('sends one personalized setup scoring digest separating green improvements, red drops, newly scored watchlist stocks, and other notices', function () {
    Notification::fake();

    $userA = User::factory()->create(['email_setup_scoring_digest' => true]);
    $userB = User::factory()->create(['email_setup_scoring_digest' => true]);

    attachSymbolsToUserWatchlist($userA, ['NBIS', 'NVDA', 'AAPL', 'TSLA', 'PLTR']);
    attachSymbolsToUserWatchlist($userB, ['MSFT']);

    $run = StockBuySetupRun::create([
        'started_at' => now()->subMinutes(2),
        'completed_at' => now(),
        'status' => 'completed',
        'symbols_considered' => 10,
        'scored_count' => 10,
        'matched_count' => 6,
    ]);

    // Watchlist improvements for User A:
    // NBIS: 42 -> 67 (+25)
    createSetupAlertForRun($run->id, 'NBIS', 67, 42);
    // NVDA: 70 -> 82 (+12)
    createSetupAlertForRun($run->id, 'NVDA', 82, 70);

    // Watchlist drops for User A:
    // AAPL: 81 -> 59 (-22)
    createSetupAlertForRun($run->id, 'AAPL', 59, 81);
    // TSLA: 65 -> 55 (-10)
    createSetupAlertForRun($run->id, 'TSLA', 55, 65);

    // Newly scored watchlist security for User A (previous_setup_score === null):
    // PLTR: first score 78 (must NOT be classified as 0 -> 78 improvement)
    createSetupAlertForRun($run->id, 'PLTR', 78, null);

    // Non-watchlist notice for User A (watched by User B):
    // MSFT: 60 -> 88 (+28)
    createSetupAlertForRun($run->id, 'MSFT', 88, 60);

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_SETUP_SCORING, $run->id);

    Notification::assertSentToTimes($userA, SetupScoringDigestNotification::class, 1);
    Notification::assertSentToTimes($userB, SetupScoringDigestNotification::class, 1);

    Notification::assertSentTo($userA, SetupScoringDigestNotification::class, function (SetupScoringDigestNotification $notification) use ($userA) {
        $digest = $notification->digest;

        // Section A: Watchlist improvements sorted by largest positive delta (+25 before +12)
        expect(array_column($digest['watchlist_improvements'], 'symbol'))->toBe(['NBIS', 'NVDA']);
        expect($digest['watchlist_improvements'][0]['previous_setup_score'])->toBe(42)
            ->and($digest['watchlist_improvements'][0]['setup_score'])->toBe(67)
            ->and($digest['watchlist_improvements'][0]['score_delta'])->toBe(25);

        // Section B: Watchlist drops sorted by largest drop first (-22 before -10)
        expect(array_column($digest['watchlist_drops'], 'symbol'))->toBe(['AAPL', 'TSLA']);
        expect($digest['watchlist_drops'][0]['previous_setup_score'])->toBe(81)
            ->and($digest['watchlist_drops'][0]['setup_score'])->toBe(59)
            ->and($digest['watchlist_drops'][0]['score_delta'])->toBe(-22);

        // Section C: Newly scored watchlist securities separated from improvements
        expect(array_column($digest['watchlist_newly_scored'], 'symbol'))->toBe(['PLTR']);
        expect($digest['watchlist_newly_scored'][0]['previous_setup_score'])->toBeNull()
            ->and($digest['watchlist_newly_scored'][0]['is_newly_scored'])->toBeTrue();

        // Section D: Other significant setup notices outside User A's watchlist
        expect(array_column($digest['other_notices'], 'symbol'))->toBe(['MSFT']);

        // Verify HTML and plain-text indicators and formatting
        $viewData = $notification->buildViewData($userA);
        $html = view('emails.digests.setup-scoring-html', $viewData)->render();
        $text = view('emails.digests.setup-scoring-text', $viewData)->render();

        expect($html)->toContain('🟢 Your Watchlist — Setup Score Improvements')
            ->and($html)->toContain('🟢 NBIS — 42 → 67 (+25)')
            ->and($html)->toContain('🔴 Your Watchlist — Setup Score Drops')
            ->and($html)->toContain('🔴 AAPL — 81 → 59 (-22)')
            ->and($html)->toContain('🆕 Newly Scored Watchlist Securities')
            ->and($html)->toContain('🆕 PLTR — Score 78 (Initial Score)')
            ->and($html)->toContain('/stock/nbis')
            ->and($text)->toContain('🟢 NBIS — 42 → 67 (+25)')
            ->and($text)->toContain('🔴 AAPL — 81 → 59 (-22)');

        return true;
    });

    // Verify User B receives personalized content where MSFT is in their Watchlist Improvements
    Notification::assertSentTo($userB, SetupScoringDigestNotification::class, function (SetupScoringDigestNotification $notification) {
        $digest = $notification->digest;

        expect(array_column($digest['watchlist_improvements'], 'symbol'))->toBe(['MSFT']);
        expect(array_column($digest['other_notices'], 'symbol'))->toContain('NVDA', 'PLTR', 'NBIS');
        expect(array_column($digest['other_notices'], 'symbol'))->not->toContain('MSFT');

        return true;
    });
});

it('consolidates multiple setup detector matches for the same symbol in one logical run and caps non-watchlist notices', function () {
    Notification::fake();

    config(['market_data.buy_setup_scanner.digest_other_notices_limit' => 10]);

    $user = User::factory()->create(['email_setup_scoring_digest' => true]);
    attachSymbolsToUserWatchlist($user, ['MULTISYM']);

    $run = StockBuySetupRun::create([
        'started_at' => now()->subMinutes(2),
        'completed_at' => now(),
        'status' => 'completed',
        'symbols_considered' => 25,
        'scored_count' => 25,
        'matched_count' => 21,
    ]);

    // Same symbol matched two setup types in the same run: 50 -> 72 and 50 -> 84
    createSetupAlertForRun($run->id, 'MULTISYM', 72, 50, 'heartbeat_consolidation_spike', '2026-11-02');
    createSetupAlertForRun($run->id, 'MULTISYM', 84, 50, 'range_compression_breakout', '2026-11-03');

    // Create 18 non-watchlist significant setup notices
    for ($i = 1; $i <= 18; $i++) {
        createSetupAlertForRun($run->id, sprintf('OTHER%02d', $i), 60 + $i, 50);
    }

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_SETUP_SCORING, $run->id);

    Notification::assertSentTo($user, SetupScoringDigestNotification::class, function (SetupScoringDigestNotification $notification) {
        $digest = $notification->digest;

        // MULTISYM consolidated into a single watchlist improvement entry using the highest score (84, +34)
        expect($digest['watchlist_improvements'])->toHaveCount(1);
        expect($digest['watchlist_improvements'][0]['symbol'])->toBe('MULTISYM')
            ->and($digest['watchlist_improvements'][0]['previous_setup_score'])->toBe(50)
            ->and($digest['watchlist_improvements'][0]['setup_score'])->toBe(84)
            ->and($digest['watchlist_improvements'][0]['score_delta'])->toBe(34);

        // Non-watchlist notices capped at configured 10, while summary_counts preserves total (18)
        expect($digest['other_notices'])->toHaveCount(10);
        expect($digest['summary_counts']['other_notices_displayed'])->toBe(10);
        expect($digest['summary_counts']['other_notices_total'])->toBe(18);

        return true;
    });
});

it('tracks previous_setup_score and score_delta across consecutive EvaluateStockBuySetup runs and triggers digest on run completion', function () {
    Notification::fake();

    $user = User::factory()->create(['email_setup_scoring_digest' => true]);
    attachSymbolsToUserWatchlist($user, ['NBIS']);

    $baseDate = \Carbon\CarbonImmutable::today()->subDays(280);
    $bars = [];
    for ($i = 0; $i < 260; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 20.0,
            'high' => 21.0,
            'low' => 19.5,
            'close' => 20.5,
            'adj_close' => 20.5,
            'volume' => 250000,
        ];
    }

    $mockProvider = Mockery::mock(\App\Services\MarketData\MarketDataProvider::class);
    $mockProvider->shouldReceive('profile')->andReturn([
        'company_name' => 'Nebius Group N.V.',
        'exchange' => 'NASDAQ',
        'market_cap' => 5_000_000_000,
        'price' => 25.0,
        'shares_outstanding' => 200_000_000,
    ]);
    $mockProvider->shouldReceive('historicalDailyBars')->andReturn($bars);
    $mockProvider->shouldReceive('quarterlyIncomeStatements')->andReturn([]);
    $mockProvider->shouldReceive('quarterlyBalanceSheets')->andReturn([]);
    $mockProvider->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

    $spikeDate = \Carbon\CarbonImmutable::today()->subDays(5);
    $baseStart = \Carbon\CarbonImmutable::today()->subDays(65);
    $baseEnd = \Carbon\CarbonImmutable::today()->subDays(6);

    $makeScannerResult = function () use ($spikeDate, $baseStart, $baseEnd) {
        $res = new \App\Services\Stocks\StockBuySetupResult(
            symbol: 'NBIS',
            setupType: 'heartbeat_consolidation_spike',
            companyName: 'Nebius Group N.V.',
            exchange: 'NASDAQ',
            marketCap: 5_000_000_000,
            marketCapCategory: 'medium',
            spikeDate: $spikeDate,
            spikeVolume: 1_500_000,
            prior52wMaxVolume: 1_000_000,
            max104wVolume: 1_200_000,
            is52wHighVolume: true,
            is104wHighVolume: true,
            daysSincePreviousComparableSpike: null,
            spikeAgeBars: 5,
            spikeRarityPoints: 20,
            spikeRarityDescription: '104-week high volume',
            baseStart: $baseStart,
            baseEnd: $baseEnd,
            baseDurationDays: 60,
            baseHigh: 21.0,
            baseLow: 19.0,
            rangeCompressionPct: 10.0,
            atrContractionRatio: 0.5,
            volumeDryUpScore: 80.0,
            slope: 0.001,
            distanceToBreakoutPct: 2.0,
            maAlignment: 'bullish',
            relativeStrengthScore: 85.0,
            price: 25.0,
            sharesOutstanding: 200_000_000,
            floatShares: 180_000_000,
            freeFloat: 0.9,
        );
        $res->reasonSummary = 'Strong consolidation breakout';

        return $res;
    };

    $mockScanner = Mockery::mock(\App\Services\Stocks\StockBuySetupScanner::class);
    $mockScanner->shouldReceive('evaluateAll')->andReturnUsing(fn () => [$makeScannerResult()]);

    $mockScorer = Mockery::mock(\App\Services\Stocks\StockBuySetupScorer::class);
    $mockScorer->shouldReceive('breakdown')->andReturn([]);
    $mockScorer->shouldReceive('scoreMetaFromBreakdown')->twice()->andReturn(
        ['raw' => 62, 'max' => 100, 'normalized' => 62],
        ['raw' => 84, 'max' => 100, 'normalized' => 84],
    );
    $mockScorer->shouldReceive('growthSynergyBonus')->andReturn(['points' => 0]);
    $mockScorer->shouldReceive('ignitionBonus')->andReturn(['points' => 0]);

    $mockLiquidity = Mockery::mock(\App\Services\Stocks\StockBuySetupLiquidityPenalty::class);
    $mockLiquidity->shouldReceive('apply')->twice()->andReturn(
        ['adjusted_score' => 62, 'average_volume' => 250000, 'turnover_pct' => 0.5, 'penalty_pct' => 0.0, 'penalty_points' => 0],
        ['adjusted_score' => 84, 'average_volume' => 250000, 'turnover_pct' => 0.5, 'penalty_pct' => 0.0, 'penalty_points' => 0],
    );

    // Run #1: First time NBIS is scored (62)
    $run1 = StockBuySetupRun::create([
        'started_at' => now()->subMinutes(10),
        'status' => 'running',
        'trigger' => 'cli',
        'symbols_considered' => 1,
    ]);

    (new \App\Jobs\EvaluateStockBuySetup(symbol: 'NBIS', scanRunId: $run1->id))->handle(
        $mockProvider,
        $mockScanner,
        $mockScorer,
        $mockLiquidity,
        app(\App\Services\Stocks\StockFundamentalsAnalyzer::class),
        app(\App\Services\Stocks\StockProvisioner::class),
    );

    $alertAfterRun1 = StockBuySetupAlert::where('symbol', 'NBIS')->first();
    expect($alertAfterRun1->setup_score)->toBe(62)
        ->and($alertAfterRun1->previous_setup_score)->toBeNull()
        ->and($alertAfterRun1->score_delta)->toBeNull()
        ->and($alertAfterRun1->scan_run_id)->toBe($run1->id)
        ->and($alertAfterRun1->first_scan_run_id)->toBe($run1->id);

    Notification::assertNotSentTo($user, \App\Notifications\StockBuySetupDetected::class);
    Notification::assertSentToTimes($user, SetupScoringDigestNotification::class, 1);

    // Run #2: NBIS score improves from 62 -> 84 (+22)
    $run2 = StockBuySetupRun::create([
        'started_at' => now()->subMinute(),
        'status' => 'running',
        'trigger' => 'cli',
        'symbols_considered' => 1,
    ]);

    (new \App\Jobs\EvaluateStockBuySetup(symbol: 'NBIS', scanRunId: $run2->id))->handle(
        $mockProvider,
        $mockScanner,
        $mockScorer,
        $mockLiquidity,
        app(\App\Services\Stocks\StockFundamentalsAnalyzer::class),
        app(\App\Services\Stocks\StockProvisioner::class),
    );

    $alertAfterRun2 = StockBuySetupAlert::where('symbol', 'NBIS')->first();
    expect($alertAfterRun2->setup_score)->toBe(84)
        ->and($alertAfterRun2->previous_setup_score)->toBe(62)
        ->and($alertAfterRun2->score_delta)->toBe(22)
        ->and($alertAfterRun2->scan_run_id)->toBe($run2->id)
        ->and($alertAfterRun2->first_scan_run_id)->toBe($run1->id);

    Notification::assertSentToTimes($user, SetupScoringDigestNotification::class, 2);
});
