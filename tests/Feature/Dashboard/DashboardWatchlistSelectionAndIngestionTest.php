<?php

namespace Tests\Feature\Dashboard;

use App\Enums\UserRole;
use App\Jobs\IngestHistoricalBarsJob;
use App\Jobs\PrefetchUserDashboardJob;
use App\Jobs\PrewarmDashboardCacheJob;
use App\Livewire\Dashboard;
use App\Livewire\Watchlists\Index as WatchlistIndex;
use App\Models\Stock;
use App\Models\StockDailyBar;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\Dashboard\DashboardQueryService;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MarketData\PersistentMarketDataProvider;
use App\Services\Watchlists\WatchlistResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWatchlistSelectionAndIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_default_personal_watchlist_selection_and_automatic_assignment(): void
    {
        $user = User::factory()->create();

        // Create first personal watchlist (not explicitly marked default)
        $w1 = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'First List', 'is_default' => false]);
        // Create second personal watchlist
        $w2 = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'Second List', 'is_default' => false]);

        $resolver = app(WatchlistResolver::class);

        // Oldest personal watchlist should be designated default automatically
        $resolved = $resolver->resolveForUser($user);
        $this->assertNotNull($resolved);
        $this->assertEquals($w1->id, $resolved->id);
        $this->assertTrue((bool) $w1->fresh()->is_default);
        $this->assertFalse((bool) $w2->fresh()->is_default);

        // Setting w2 as default via Livewire
        Livewire::actingAs($user)
            ->test(WatchlistIndex::class)
            ->call('setDefault', $w2->id);

        $this->assertFalse((bool) $w1->fresh()->is_default);
        $this->assertTrue((bool) $w2->fresh()->is_default);

        $resolvedAfterChange = $resolver->resolveForUser($user);
        $this->assertEquals($w2->id, $resolvedAfterChange->id);
    }

    public function test_deleting_default_watchlist_automatically_selects_next_personal_watchlist(): void
    {
        $user = User::factory()->create();

        $w1 = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'W1', 'is_default' => true]);
        $w2 = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'W2', 'is_default' => false]);
        $w3 = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'W3', 'is_default' => false]);

        // Delete current default w1
        Livewire::actingAs($user)
            ->test(WatchlistIndex::class)
            ->call('delete', $w1->id);

        $resolver = app(WatchlistResolver::class);
        $resolved = $resolver->resolveForUser($user);

        $this->assertNotNull($resolved);
        $this->assertEquals($w2->id, $resolved->id);
        $this->assertTrue((bool) $w2->fresh()->is_default);
        $this->assertFalse((bool) $w3->fresh()->is_default);
    }

    public function test_global_watchlists_cannot_be_designated_as_personal_defaults(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMINISTRATOR]);

        $global = Watchlist::factory()->create([
            'user_id' => $admin->id,
            'name' => 'Admin Global',
            'is_global' => true,
            'is_default' => false,
        ]);

        $personal = Watchlist::factory()->create([
            'user_id' => $admin->id,
            'name' => 'Admin Personal',
            'is_global' => false,
            'is_default' => true,
        ]);

        // Attempting to set global watchlist as default
        Livewire::actingAs($admin)
            ->test(WatchlistIndex::class)
            ->call('setDefault', $global->id);

        $this->assertFalse((bool) $global->fresh()->is_default);
        $this->assertTrue((bool) $personal->fresh()->is_default);

        $resolver = app(WatchlistResolver::class);
        $resolved = $resolver->resolveForUser($admin);
        $this->assertEquals($personal->id, $resolved->id);
    }

    public function test_global_watchlist_fallback_when_user_has_no_personal_watchlists(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMINISTRATOR]);
        $global = Watchlist::factory()->create([
            'user_id' => $admin->id,
            'name' => 'Global Master List',
            'is_global' => true,
        ]);

        $regularUser = User::factory()->create(['role' => UserRole::EQUITIES]);

        $resolver = app(WatchlistResolver::class);
        $resolved = $resolver->resolveForUser($regularUser);

        $this->assertNotNull($resolved);
        $this->assertEquals($global->id, $resolved->id);
    }

    public function test_consistent_selection_across_dashboard_and_prefetch_jobs(): void
    {
        $user = User::factory()->create();
        $w1 = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'Personal List', 'is_default' => true]);

        $this->actingAs($user);
        $dashboard = new Dashboard;
        $fromDashboard = $dashboard->resolveWatchlist();

        $resolver = app(WatchlistResolver::class);
        $fromResolver = $resolver->resolveForUser($user);

        $this->assertNotNull($fromDashboard);
        $this->assertNotNull($fromResolver);
        $this->assertEquals($w1->id, $fromDashboard->id);
        $this->assertEquals($w1->id, $fromResolver->id);
    }

    public function test_no_synchronous_upstream_http_requests_during_highest_gaining_stocks_rendering(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        $stock = Stock::factory()->create(['symbol' => 'NVDA', 'company_name' => 'NVIDIA Corp']);
        WatchlistItem::factory()->create([
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'created_at' => now()->subDays(10),
        ]);

        // Mock provider to assert upstream is not called synchronously
        $upstreamMock = $this->createMock(MarketDataProvider::class);
        $upstreamMock->expects($this->never())->method('historicalDailyBars');

        $persistentProvider = new PersistentMarketDataProvider($upstreamMock);
        $queryService = app(DashboardQueryService::class);

        // When DB has no bars for NVDA
        $gainers = $queryService->highestGainingStocks($watchlist, $persistentProvider);

        // Does not show misleading 0% gain
        $this->assertTrue($gainers->isEmpty());

        // Dispatches background ingestion job
        Queue::assertPushed(IngestHistoricalBarsJob::class, function ($job) {
            return $job->symbol === 'NVDA';
        });
    }

    public function test_multiple_concurrent_users_do_not_trigger_duplicate_ingestion(): void
    {
        Queue::fake();

        // First user triggers ingestion
        $dispatched1 = IngestHistoricalBarsJob::dispatchIfNeeded('NVDA', '2026-11-01', '2026-11-09');
        $this->assertTrue($dispatched1);

        // Second user simultaneously requests missing bars for NVDA
        $dispatched2 = IngestHistoricalBarsJob::dispatchIfNeeded('NVDA', '2026-11-01', '2026-11-09');
        $this->assertFalse($dispatched2);

        Queue::assertPushed(IngestHistoricalBarsJob::class, 1);
    }

    public function test_successful_ingestion_makes_updated_calculations_available_on_next_dashboard_load(): void
    {
        $user = User::factory()->create();
        $watchlist = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        $stock = Stock::factory()->create(['symbol' => 'MSFT', 'company_name' => 'Microsoft Corp']);
        $item = WatchlistItem::factory()->create([
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'created_at' => CarbonImmutable::parse('2026-11-01'),
        ]);

        $fakeProvider = new FakeMarketDataProvider;
        $fakeProvider->dailyBars['MSFT'] = [
            ['date' => '2026-11-01', 'close' => 100.0, 'open' => 100.0, 'high' => 105.0, 'low' => 99.0, 'volume' => 1000],
            ['date' => '2026-11-09', 'close' => 150.0, 'open' => 140.0, 'high' => 155.0, 'low' => 139.0, 'volume' => 2000],
        ];

        // Wrap fake provider in PersistentMarketDataProvider
        $persistentProvider = new PersistentMarketDataProvider($fakeProvider);
        $cacheService = app(DashboardCacheService::class);

        // Run ingestion job
        $job = new IngestHistoricalBarsJob('MSFT', '2026-11-01', '2026-11-09');
        $job->handle($persistentProvider, $cacheService);

        // Verify bars are stored in DB
        $this->assertEquals(2, StockDailyBar::where('symbol', 'MSFT')->count());

        // Next dashboard query immediately calculates gain (+50%)
        $gainers = $cacheService->userGainers($user, $watchlist, $persistentProvider);
        $this->assertNotEmpty($gainers);
        $this->assertEquals('MSFT', $gainers->first()['symbol']);
        $this->assertEquals(50.0, $gainers->first()['gain_pct']);
    }

    public function test_failed_ingestion_applies_cooldown_to_prevent_request_storms(): void
    {
        $fakeProvider = new FakeMarketDataProvider;
        $fakeProvider->errors[] = ['path' => 'bars', 'status' => 500, 'body' => 'Internal Server Error'];

        $persistentProvider = new PersistentMarketDataProvider($fakeProvider);
        $cacheService = app(DashboardCacheService::class);

        $job = new IngestHistoricalBarsJob('BADSYM', '2026-11-01', '2026-11-09');
        $job->handle($persistentProvider, $cacheService);

        // Cooldown key should be set
        $this->assertTrue(Cache::has('bars_ingest:cooldown:BADSYM'));

        // Subsequent dispatchIfNeeded should be rejected by cooldown
        $dispatched = IngestHistoricalBarsJob::dispatchIfNeeded('BADSYM', '2026-11-01', '2026-11-09');
        $this->assertFalse($dispatched);
    }

    public function test_previously_queued_jobs_cannot_overwrite_results_for_a_newly_selected_default(): void
    {
        $user = User::factory()->create();
        $wOld = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'Old Default', 'is_default' => false]);
        $wNew = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'New Default', 'is_default' => true]);

        $stockOld = Stock::factory()->create(['symbol' => 'OLD', 'company_name' => 'Old Co']);
        WatchlistItem::factory()->create([
            'watchlist_id' => $wOld->id,
            'stock_id' => $stockOld->id,
            'created_at' => now()->subDays(2),
        ]);

        $stockNew = Stock::factory()->create(['symbol' => 'NEW', 'company_name' => 'New Co']);
        WatchlistItem::factory()->create([
            'watchlist_id' => $wNew->id,
            'stock_id' => $stockNew->id,
            'created_at' => now()->subDays(2),
        ]);

        StockDailyBar::create([
            'symbol' => 'OLD',
            'bar_date' => now()->subDay()->toDateString(),
            'open' => 10, 'high' => 12, 'low' => 9, 'close' => 10, 'volume' => 100,
        ]);
        StockDailyBar::create([
            'symbol' => 'OLD',
            'bar_date' => now()->toDateString(),
            'open' => 10, 'high' => 12, 'low' => 9, 'close' => 15, 'volume' => 100,
        ]);

        StockDailyBar::create([
            'symbol' => 'NEW',
            'bar_date' => now()->subDay()->toDateString(),
            'open' => 100, 'high' => 120, 'low' => 90, 'close' => 100, 'volume' => 100,
        ]);
        StockDailyBar::create([
            'symbol' => 'NEW',
            'bar_date' => now()->toDateString(),
            'open' => 100, 'high' => 120, 'low' => 90, 'close' => 200, 'volume' => 100,
        ]);

        $provider = new FakeMarketDataProvider;
        $cacheService = app(DashboardCacheService::class);

        // Prewarm cache for active default (wNew)
        $cacheService->refreshUserDashboard($user, $wNew, $provider);
        $gainers = $cacheService->userGainers($user, $wNew, $provider);
        $this->assertEquals('NEW', $gainers->first()['symbol']);

        // An old background prefetch job was queued for wOld and now finishes
        $cacheService->refreshUserDashboard($user, $wOld, $provider);

        // The active cache must still contain NEW, not overwritten by OLD
        $gainersAfterStaleJob = $cacheService->userGainers($user, $wNew, $provider);
        $this->assertEquals('NEW', $gainersAfterStaleJob->first()['symbol']);
    }
}
