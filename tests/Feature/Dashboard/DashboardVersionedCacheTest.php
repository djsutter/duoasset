<?php

use App\Enums\UserRole;
use App\Jobs\PrefetchUserDashboardJob;
use App\Models\EarningsEvent;
use App\Models\MarketDataPayload;
use App\Models\SectorFlowSnapshot;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function seedStocksDashboardFixtures(string $setupSymbol = 'NVDA', int $setupScore = 94): void
{
    if (! Stock::where('symbol', $setupSymbol)->exists()) {
        Stock::factory()->create([
            'symbol' => $setupSymbol,
            'company_name' => "{$setupSymbol} Corp",
            'exchange' => 'NASDAQ',
            'currency' => 'USD',
        ]);
    }

    StockBuySetupAlert::updateOrCreate(
        ['symbol' => $setupSymbol, 'setup_type' => 'vcp_breakout', 'spike_date' => '2026-11-04'],
        [
            'company_name' => "{$setupSymbol} Corp",
            'setup_score' => $setupScore,
            'exchange' => 'NASDAQ',
            'price' => 142.50,
            'market_cap' => 350000000000,
            'source' => 'test',
        ]
    );

    EarningsEvent::updateOrCreate(
        ['source' => 'fmp', 'symbol' => 'MSFT', 'report_date' => '2026-11-03'],
        [
            'company_name' => 'Microsoft Corp',
            'exchange' => 'NASDAQ',
            'eps_estimated' => 2.50,
            'eps_actual' => 3.25,
            'eps_surprise' => 0.75,
            'eps_surprise_percent' => 30.0,
        ]
    );

    SectorFlowSnapshot::query()->whereIn('sector', ['technology', 'utilities'])->delete();

    SectorFlowSnapshot::factory()->create([
        'sector' => 'technology',
        'interval' => 'hourly',
        'captured_at' => now()->startOfMinute(),
        'label' => 'Technology',
        'daily_change_pct' => 2.45,
        'hourly_change_pct' => 0.65,
        'direction' => 'leading',
    ]);

    SectorFlowSnapshot::factory()->create([
        'sector' => 'utilities',
        'interval' => 'hourly',
        'captured_at' => now()->startOfMinute(),
        'label' => 'Utilities',
        'daily_change_pct' => -1.35,
        'hourly_change_pct' => -0.40,
        'direction' => 'fading',
    ]);
}

test('1-4. active dashboard snapshot is versioned, keeps old version available during warming, and switches active pointer atomically', function () {
    // Exercise against database cache store to verify atomic pointer operations
    config(['cache.default' => 'database']);
    Cache::flush();

    seedStocksDashboardFixtures('NVDA', 94);
    $cacheService = app(DashboardCacheService::class);

    // Initial warm publishes v1 as ACTIVE
    $v1 = $cacheService->warmContextSnapshot('stocks');
    expect($v1)->not->toBeNull()
        ->and($v1['snapshot_version'])->toBe(1)
        ->and($v1['status'])->toBe('ACTIVE')
        ->and(Cache::get(DashboardCacheService::activePointerKey('stocks')))->toBe(1);

    $activeBefore = $cacheService->getActiveSnapshot('stocks');
    expect($activeBefore['snapshot_version'])->toBe(1)
        ->and($activeBefore['datasets']['top_scoring_setups'][0]['symbol'])->toBe('NVDA');

    // Begin warming v2 in the background
    $warming = $cacheService->beginWarming('stocks');
    expect($warming['version'])->toBe(2);

    // While v2 is BUILDING, v1 remains ACTIVE and served to readers
    $buildingPayload = Cache::get(DashboardCacheService::buildingSnapshotKey('stocks', 2));
    expect($buildingPayload['status'])->toBe('BUILDING');

    $duringWarm = $cacheService->getActiveSnapshot('stocks');
    expect($duringWarm['snapshot_version'])->toBe(1)
        ->and($duringWarm['datasets']['top_scoring_setups'][0]['symbol'])->toBe('NVDA');

    // Update underlying data and complete warming v2
    seedStocksDashboardFixtures('AAPL', 98);
    $v2 = $cacheService->warmContextSnapshot('stocks', null, $warming['generation'], $warming['version']);

    expect($v2)->not->toBeNull()
        ->and($v2['snapshot_version'])->toBe(2)
        ->and(Cache::get(DashboardCacheService::activePointerKey('stocks')))->toBe(2)
        ->and(Cache::get(DashboardCacheService::previousPointerKey('stocks')))->toBe(1);

    $prevSnapshot = Cache::get(DashboardCacheService::versionSnapshotKey('stocks', 1));
    expect($prevSnapshot['status'])->toBe('PREVIOUS');

    $activeAfter = $cacheService->getActiveSnapshot('stocks');
    expect($activeAfter['snapshot_version'])->toBe(2)
        ->and($activeAfter['datasets']['top_scoring_setups'][0]['symbol'])->toBe('AAPL');
});

test('5-6. failed or invalid warming preserves the existing active dashboard snapshot and logs failure metadata', function () {
    seedStocksDashboardFixtures('NVDA', 94);
    $cacheService = app(DashboardCacheService::class);

    $v1 = $cacheService->warmContextSnapshot('stocks');
    expect($v1['snapshot_version'])->toBe(1);

    // Case H: Warmer throws mid-flight after partial work
    $failedResult = $cacheService->warmContextSnapshot('stocks', function () {
        throw new RuntimeException('Upstream market data provider timed out');
    });

    expect($failedResult)->toBeNull()
        ->and(Cache::get(DashboardCacheService::activePointerKey('stocks')))->toBe(1);

    $meta = $cacheService->getSnapshotMetadata('stocks');
    expect($meta['snapshot_version'])->toBe(1)
        ->and($meta['last_refresh_status'])->toBe('failed')
        ->and($meta['last_error'])->toContain('Upstream market data provider timed out')
        ->and($meta['has_valid_snapshot'])->toBeTrue();

    // Case: Warmer returns incomplete/invalid schema (missing required dataset)
    $invalidResult = $cacheService->warmContextSnapshot('stocks', function () {
        return [
            'top_scoring_setups' => [['symbol' => 'NVDA', 'setup_score' => 94]],
            // missing highest_eps_surprises, money_flow_gainers, money_flow_losers
        ];
    });

    expect($invalidResult)->toBeNull()
        ->and(Cache::get(DashboardCacheService::activePointerKey('stocks')))->toBe(1);

    $stillActive = $cacheService->getActiveSnapshot('stocks');
    expect($stillActive['snapshot_version'])->toBe(1)
        ->and($stillActive['datasets']['top_scoring_setups'][0]['symbol'])->toBe('NVDA');
});

test('7. concurrent warmers use generation fencing so a slower older warmer cannot overwrite a newer published snapshot', function () {
    seedStocksDashboardFixtures('NVDA', 90);
    $cacheService = app(DashboardCacheService::class);

    // Warmer A starts at 10:00 (generation 1, version 1)
    $warmerA = $cacheService->beginWarming('stocks');
    expect($warmerA['generation'])->toBe(1)
        ->and($warmerA['version'])->toBe(1);

    // Warmer B starts at 10:01 (generation 2, version 2)
    $warmerB = $cacheService->beginWarming('stocks');
    expect($warmerB['generation'])->toBe(2)
        ->and($warmerB['version'])->toBe(2);

    // Warmer B finishes first at 10:02 with newer data (score 99)
    seedStocksDashboardFixtures('NEWB', 99);
    $publishedB = $cacheService->warmContextSnapshot('stocks', null, $warmerB['generation'], $warmerB['version']);
    expect($publishedB)->not->toBeNull()
        ->and($publishedB['snapshot_version'])->toBe(2)
        ->and(Cache::get(DashboardCacheService::activePointerKey('stocks')))->toBe(2);

    // Warmer A finishes later at 10:03 with stale data — must be rejected by generation fencing
    $staleDatasetsFromA = [
        'top_scoring_setups' => [['symbol' => 'OLDA', 'company_name' => 'Old A', 'setup_score' => 80, 'setup_type' => 'vcp_breakout']],
        'highest_eps_surprises' => [],
        'money_flow_gainers' => [],
        'money_flow_losers' => [],
    ];
    $publishedA = $cacheService->publishSnapshot('stocks', $staleDatasetsFromA, $warmerA['generation'], $warmerA['version']);

    expect($publishedA)->toBeNull()
        ->and(Cache::get(DashboardCacheService::activePointerKey('stocks')))->toBe(2);

    $active = $cacheService->getActiveSnapshot('stocks');
    expect($active['snapshot_version'])->toBe(2)
        ->and($active['datasets']['top_scoring_setups'][0]['symbol'])->toBe('NEWB');
});

test('8 & 14. cache keys are isolated by context and obsolete snapshot versions are cleaned up automatically', function () {
    seedStocksDashboardFixtures('NVDA', 94);
    $cacheService = app(DashboardCacheService::class);

    $cacheService->warmContextSnapshot('stocks'); // v1
    $cacheService->warmContextSnapshot('stocks'); // v2
    $cacheService->warmContextSnapshot('stocks'); // v3
    $cacheService->warmContextSnapshot('stocks'); // v4

    $cacheService->warmContextSnapshot('crypto'); // crypto v1

    expect(Cache::get(DashboardCacheService::activePointerKey('stocks')))->toBe(4)
        ->and(Cache::get(DashboardCacheService::previousPointerKey('stocks')))->toBe(3)
        ->and(Cache::get(DashboardCacheService::activePointerKey('crypto')))->toBe(1)
        ->and(Cache::has(DashboardCacheService::versionSnapshotKey('stocks', 4)))->toBeTrue()
        ->and(Cache::has(DashboardCacheService::versionSnapshotKey('stocks', 3)))->toBeTrue()
        ->and(Cache::has(DashboardCacheService::versionSnapshotKey('stocks', 1)))->toBeFalse()
        ->and(Cache::has(DashboardCacheService::versionSnapshotKey('stocks', 2)))->toBeFalse();
});

test('9-11. routine cache clear recovers durable last-known-good snapshot immediately while hard purge removes both', function () {
    Queue::fake();
    seedStocksDashboardFixtures('NVDA', 94);

    $user = User::factory()->equities()->create(['email_verified_at' => now()]);
    $cacheService = app(DashboardCacheService::class);

    // 1. Warm the dashboard so a durable snapshot exists in MarketDataPayload
    $cacheService->warmContextSnapshot('stocks');
    expect(MarketDataPayload::where('symbol', DashboardCacheService::DURABLE_SYMBOL)->where('data_type', 'dashboard_snapshot_stocks')->exists())->toBeTrue();

    // 2. Delete underlying DB rows to prove the post-clear request serves the recovered durable snapshot without rebuilding from DB
    StockBuySetupAlert::query()->delete();

    // 3. Execute routine cache clear (`php artisan cache:clear` / `./clearcache`)
    $this->artisan('cache:clear')->assertSuccessful();
    expect(Cache::has(DashboardCacheService::activePointerKey('stocks')))->toBeFalse();

    // 4. Request /dashboard: recovers durable snapshot immediately and dispatches background warmer
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('NVDA')
        ->assertSee('94');

    $meta = $cacheService->getSnapshotMetadata('stocks');
    expect($meta['used_durable_fallback'])->toBeTrue();
    Queue::assertPushed(PrefetchUserDashboardJob::class);

    // 5. Incompatible schema version in durable snapshot is safely rejected without crashing
    $record = MarketDataPayload::where('symbol', DashboardCacheService::DURABLE_SYMBOL)
        ->where('data_type', 'dashboard_snapshot_stocks')
        ->first();
    $corrupted = $record->payload;
    $corrupted['schema_version'] = 999;
    $record->update(['payload' => $corrupted]);
    Cache::flush();

    expect($cacheService->getActiveSnapshot('stocks'))->toBeNull();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    // 6. Explicit hard purge command deletes both Cache keys and durable MarketDataPayload records
    $cacheService->warmContextSnapshot('stocks');
    $this->artisan('dashboard:cache-purge', ['--context' => 'all'])->assertSuccessful();

    expect(Cache::has(DashboardCacheService::activePointerKey('stocks')))->toBeFalse()
        ->and(MarketDataPayload::where('symbol', DashboardCacheService::DURABLE_SYMBOL)->count())->toBe(0);
});

test('12-13. user-specific watchlist gainers are isolated per identity and authorization is enforced even when cached data exists', function () {
    seedStocksDashboardFixtures('NVDA', 94);

    $userA = User::factory()->equities()->create(['email_verified_at' => now()]);
    $userB = User::factory()->equities()->create(['email_verified_at' => now()]);
    $cryptoOnlyUser = User::factory()->crypto()->create(['email_verified_at' => now()]);

    $wlA = Watchlist::create(['user_id' => $userA->id, 'name' => 'User A Private List', 'is_default' => true]);
    $wlB = Watchlist::create(['user_id' => $userB->id, 'name' => 'User B Private List', 'is_default' => true]);

    $stockA = Stock::factory()->create(['symbol' => 'PRIV_A', 'company_name' => 'Private A Corp']);
    $stockB = Stock::factory()->create(['symbol' => 'PRIV_B', 'company_name' => 'Private B Corp']);

    WatchlistItem::factory()->create(['watchlist_id' => $wlA->id, 'stock_id' => $stockA->id, 'created_at' => now()->subDays(10)]);
    WatchlistItem::factory()->create(['watchlist_id' => $wlB->id, 'stock_id' => $stockB->id, 'created_at' => now()->subDays(10)]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['PRIV_A'] = [
        ['date' => now()->subDays(10)->toDateString(), 'close' => 10.0],
        ['date' => now()->toDateString(), 'close' => 18.0],
    ];
    $fake->dailyBars['PRIV_B'] = [
        ['date' => now()->subDays(10)->toDateString(), 'close' => 20.0],
        ['date' => now()->toDateString(), 'close' => 25.0],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    // User A sees PRIV_A, never PRIV_B
    $this->actingAs($userA)->get('/dashboard')
        ->assertOk()
        ->assertSee('PRIV_A')
        ->assertDontSee('PRIV_B');

    // User B sees PRIV_B, never PRIV_A
    $this->actingAs($userB)->get('/dashboard')
        ->assertOk()
        ->assertSee('PRIV_B')
        ->assertDontSee('PRIV_A');

    // Crypto-only user cannot see Stocks dashboard even though active stocks snapshot is cached
    $this->actingAs($cryptoOnlyUser)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('PRIV_A')
        ->assertDontSee('PRIV_B')
        ->assertDontSee('The Top Scoring Setups');
});
