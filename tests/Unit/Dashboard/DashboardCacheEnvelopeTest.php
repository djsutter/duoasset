<?php

use App\Models\EarningsEvent;
use App\Models\Stock;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\Dashboard\DashboardQueryService;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class, RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

test('rememberStale creates envelope with data and future stale_at timestamp', function () {
    Cache::flush();
    $cacheService = app(DashboardCacheService::class);

    $called = 0;
    $envelope = $cacheService->rememberStale('test_swr_key', 900, function () use (&$called) {
        $called++;

        return ['metric' => 42];
    });

    expect($called)->toBe(1)
        ->and($envelope)->toHaveKeys(['data', 'stale_at'])
        ->and($envelope['data'])->toBe(['metric' => 42])
        ->and($envelope['stale_at'])->toBeGreaterThan(now()->timestamp)
        ->and($cacheService->isStale('test_swr_key'))->toBeFalse()
        ->and($cacheService->isStale($envelope))->toBeFalse();
});

test('rememberStale returns existing envelope indefinitely even when stale_at has passed', function () {
    Cache::flush();
    $cacheService = app(DashboardCacheService::class);

    // Seed stale envelope
    $staleTimestamp = now()->subMinutes(30)->timestamp;
    Cache::forever('test_stale_key', [
        'data' => ['cached_value' => 'persisted_stale'],
        'stale_at' => $staleTimestamp,
    ]);

    $called = 0;
    $result = $cacheService->rememberStale('test_stale_key', 900, function () use (&$called) {
        $called++;

        return ['cached_value' => 'fresh'];
    });

    expect($called)->toBe(0)
        ->and($result['data'])->toBe(['cached_value' => 'persisted_stale'])
        ->and($result['stale_at'])->toBe($staleTimestamp)
        ->and($cacheService->isStale('test_stale_key'))->toBeTrue()
        ->and($cacheService->isStale($result))->toBeTrue();
});

test('isStale handles missing key, fresh key, and stale key accurately', function () {
    Cache::flush();
    $cacheService = app(DashboardCacheService::class);

    expect($cacheService->isStale('non_existent_key'))->toBeTrue();

    $cacheService->putEnvelope('fresh_key', 'some_data', 300);
    expect($cacheService->isStale('fresh_key'))->toBeFalse();

    $cacheService->putEnvelope('stale_key', 'some_data', -10);
    expect($cacheService->isStale('stale_key'))->toBeTrue();
});

test('refreshUserDashboard overwrites envelopes and updates stale_at', function () {
    Cache::flush();
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);

    $provider = Mockery::mock(MarketDataProvider::class);
    $provider->shouldReceive('historicalDailyBars')->andReturn([
        ['date' => '2026-10-01', 'close' => 150.0],
        ['date' => '2026-10-28', 'close' => 165.0],
    ]);

    $cacheService = app(DashboardCacheService::class);

    // Seed stale envelope for user gainers
    $key = DashboardCacheService::userGainersKey($user->id);
    Cache::forever($key, [
        'data' => collect(),
        'stale_at' => now()->subMinutes(10)->timestamp,
    ]);

    expect($cacheService->isStale($key))->toBeTrue();

    // Refresh
    $cacheService->refreshUserDashboard($user, $watchlist, $provider);

    expect($cacheService->isStale($key))->toBeFalse();
    $envelope = $cacheService->getEnvelope($key);
    expect($envelope)->not->toBeNull()
        ->and($envelope['stale_at'])->toBeGreaterThan(now()->timestamp);
});

test('highestEpsSurprises SQL query is bounded with limit', function () {
    // Create 60 earnings events
    for ($i = 1; $i <= 60; $i++) {
        EarningsEvent::query()->create([
            'source' => 'fmp',
            'symbol' => 'SYM'.$i,
            'report_date' => '2026-10-20',
            'eps_estimated' => 1.0,
            'eps_actual' => 1.0 + ($i * 0.1),
            'eps_surprise' => $i * 0.1,
            'eps_surprise_percent' => $i * 10.0,
            'raw' => [],
            'detected_at' => now(),
        ]);
    }

    $queries = app(DashboardQueryService::class);
    $top = $queries->highestEpsSurprises(2);

    expect($top)->toHaveCount(2)
        ->and($top[0]->symbol)->toBe('SYM60')
        ->and($top[1]->symbol)->toBe('SYM59');
});
