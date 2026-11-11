<?php

use App\Enums\UserRole;
use App\Jobs\PrefetchUserDashboardJob;
use App\Models\Stock;
use App\Models\StockDailyBar;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

test('dashboard serves stale cache envelope immediately', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $stock = Stock::factory()->create(['symbol' => 'STALE_STOCK', 'company_name' => 'Stale Corp']);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);

    $cacheService = app(DashboardCacheService::class);
    $gen = $cacheService->userGainersGeneration($user->id);

    $cacheKey = DashboardCacheService::userGainersKey($user->id);
    Cache::forever($cacheKey, [
        'data' => collect([[
            'symbol' => 'STALE_STOCK',
            'company_name' => 'Stale Corp',
            'added_at' => now()->subDays(5),
            'entry_price' => 10.0,
            'latest_price' => 15.0,
            'gain_pct' => 50.0,
        ]]),
        'stale_at' => now()->subMinutes(20)->timestamp,
        'watchlist_id' => $watchlist->id,
        'generation' => $gen,
    ]);

    Queue::fake([PrefetchUserDashboardJob::class]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200)
        ->assertSee('STALE_STOCK')
        ->assertSee('+50.00%');
});

test('visiting dashboard with stale cache dispatches background prefetch job', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);

    $cacheService = app(DashboardCacheService::class);
    $gen = $cacheService->userGainersGeneration($user->id);

    $cacheKey = DashboardCacheService::userGainersKey($user->id);
    Cache::forever($cacheKey, [
        'data' => collect([[
            'symbol' => 'AAPL',
            'company_name' => 'Apple Inc.',
            'added_at' => now()->subDays(5),
            'entry_price' => 100.0,
            'latest_price' => 110.0,
            'gain_pct' => 10.0,
        ]]),
        'stale_at' => now()->subMinutes(10)->timestamp,
        'watchlist_id' => $watchlist->id,
        'generation' => $gen,
    ]);

    Queue::fake([PrefetchUserDashboardJob::class]);

    $this->actingAs($user)->get('/dashboard')->assertStatus(200);

    Queue::assertPushed(PrefetchUserDashboardJob::class, function (PrefetchUserDashboardJob $job) use ($user) {
        return $job->userId === $user->id;
    });
});

test('concurrent requests with active lock do not dispatch duplicate prefetch jobs', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);

    $cacheService = app(DashboardCacheService::class);
    $gen = $cacheService->userGainersGeneration($user->id);

    $cacheKey = DashboardCacheService::userGainersKey($user->id);
    Cache::forever($cacheKey, [
        'data' => collect([[
            'symbol' => 'AAPL',
            'company_name' => 'Apple Inc.',
            'added_at' => now()->subDays(5),
            'entry_price' => 100.0,
            'latest_price' => 110.0,
            'gain_pct' => 10.0,
        ]]),
        'stale_at' => now()->subMinutes(10)->timestamp,
        'watchlist_id' => $watchlist->id,
        'generation' => $gen,
    ]);

    // Acquire lock simulating an in-flight background job
    $acquired = $cacheService->acquirePrefetchLock($user->id, 60);
    expect($acquired)->toBeTrue();

    Queue::fake([PrefetchUserDashboardJob::class]);

    // Second hit while locked
    $this->actingAs($user)->get('/dashboard')->assertStatus(200);

    // Should NOT push duplicate job
    Queue::assertNotPushed(PrefetchUserDashboardJob::class);
});

test('prefetch job execution updates cache envelope with fresh data and releases stampede lock', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $stock = Stock::factory()->create(['symbol' => 'FRESH_TICKER', 'company_name' => 'Fresh Co']);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);

    $cacheService = app(DashboardCacheService::class);
    $gen = $cacheService->userGainersGeneration($user->id);

    $cacheKey = DashboardCacheService::userGainersKey($user->id);
    Cache::forever($cacheKey, [
        'data' => collect([[
            'symbol' => 'FRESH_TICKER',
            'company_name' => 'Fresh Co',
            'added_at' => now()->subDays(5),
            'entry_price' => 100.0,
            'latest_price' => 110.0,
            'gain_pct' => 10.0,
        ]]),
        'stale_at' => now()->subMinutes(10)->timestamp,
        'watchlist_id' => $watchlist->id,
        'generation' => $gen,
    ]);

    $cacheService->acquirePrefetchLock($user->id, 60);

    $provider = Mockery::mock(MarketDataProvider::class);
    $provider->shouldReceive('storedDailyBars')->andReturn([
        ['date' => '2026-10-01', 'close' => 100.0],
        ['date' => '2026-10-28', 'close' => 140.0],
    ]);
    $provider->shouldReceive('historicalDailyBars')->andReturn([
        ['date' => '2026-10-01', 'close' => 100.0],
        ['date' => '2026-10-28', 'close' => 140.0],
    ]);
    $provider->shouldReceive('isDailyBarsStaleOrMissing')->andReturn(false);

    $job = new PrefetchUserDashboardJob($user->id);
    $job->handle($cacheService, $provider);

    // Envelope is now fresh
    expect($cacheService->isStale($cacheKey))->toBeFalse();
    $envelope = $cacheService->getEnvelope($cacheKey);
    expect($envelope)->not->toBeNull()
        ->and($envelope['stale_at'])->toBeGreaterThan(now()->timestamp);

    // Lock has been released, allowing future acquisition
    expect($cacheService->acquirePrefetchLock($user->id, 60))->toBeTrue();
});

test('cold start without cache envelope populates initial envelope synchronously', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $stock = Stock::factory()->create(['symbol' => 'COLD_TICKER', 'company_name' => 'Cold Corp']);
    WatchlistItem::factory()->create([
        'watchlist_id' => $watchlist->id,
        'stock_id' => $stock->id,
        'created_at' => now()->subDays(5),
    ]);

    StockDailyBar::create([
        'symbol' => 'COLD_TICKER',
        'bar_date' => now()->subDays(5)->toDateString(),
        'open' => 50.0, 'high' => 55.0, 'low' => 48.0, 'close' => 50.0, 'volume' => 1000,
    ]);
    StockDailyBar::create([
        'symbol' => 'COLD_TICKER',
        'bar_date' => now()->toDateString(),
        'open' => 70.0, 'high' => 78.0, 'low' => 69.0, 'close' => 75.0, 'volume' => 2000,
    ]);

    $cacheKey = DashboardCacheService::userGainersKey($user->id);
    Cache::forget($cacheKey);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200)
        ->assertSee('COLD_TICKER')
        ->assertSee('+50.00%');

    $cacheService = app(DashboardCacheService::class);
    $envelope = $cacheService->getEnvelope($cacheKey);
    expect($envelope)->not->toBeNull()
        ->and($envelope['stale_at'])->toBeGreaterThan(now()->timestamp)
        ->and($envelope['watchlist_id'])->toBe($watchlist->id);
});
