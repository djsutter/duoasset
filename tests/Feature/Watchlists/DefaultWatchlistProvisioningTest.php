<?php

namespace Tests\Feature\Watchlists;

use App\Enums\MoatLevel;
use App\Models\EarningsEvent;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Dashboard\DashboardQueryService;
use App\Services\Stocks\StockProvisioner;
use App\Services\Watchlists\DefaultWatchlistService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DefaultWatchlistProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_newly_created_user_gets_default_setup_watchlist(): void
    {
        $user = User::factory()->create();

        $defaultWatchlist = Watchlist::where('user_id', $user->id)
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($defaultWatchlist);
        $this->assertEquals('setup', $defaultWatchlist->name);
        $this->assertTrue((bool) $defaultWatchlist->is_default);
        $this->assertFalse((bool) $defaultWatchlist->is_global);
    }

    public function test_newly_created_user_gets_top_10_setups_and_top_10_eps_surprises(): void
    {
        // Create 12 setup alerts (so top 10 are selected)
        for ($i = 1; $i <= 12; $i++) {
            $sym = 'SETUP'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            StockBuySetupAlert::create([
                'symbol' => $sym,
                'company_name' => "Setup Company {$i}",
                'exchange' => 'NASDAQ',
                'setup_score' => 80 + $i,
                'setup_type' => 'vcp_breakout',
                'spike_date' => Carbon::now()->subDays(12 - $i)->toDateString(),
                'source' => 'test',
                'reason_summary' => "Reason for {$sym}",
            ]);
        }

        // Create 12 EPS surprises (so top 10 are selected)
        for ($i = 1; $i <= 12; $i++) {
            $sym = 'EPS'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            EarningsEvent::create([
                'symbol' => $sym,
                'company_name' => "EPS Company {$i}",
                'exchange' => 'NYSE',
                'report_date' => Carbon::now()->subDays(12 - $i)->toDateString(),
                'eps_surprise_percent' => 10.0 + ($i * 5),
                'source' => 'test',
            ]);
        }

        $user = User::factory()->create();

        $defaultWatchlist = Watchlist::where('user_id', $user->id)
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($defaultWatchlist);
        $this->assertEquals('setup', $defaultWatchlist->name);

        // Should have 20 stocks (top 10 setups + top 10 eps surprises)
        $items = $defaultWatchlist->items()->with('stock')->get();
        $this->assertCount(20, $items);

        $symbols = $items->pluck('stock.symbol')->all();

        // Check top 10 setups (SETUP03 through SETUP12)
        for ($i = 3; $i <= 12; $i++) {
            $sym = 'SETUP'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->assertContains($sym, $symbols);
        }

        // Check lower 2 setups (SETUP01, SETUP02) are not in top 10
        $this->assertNotContains('SETUP01', $symbols);
        $this->assertNotContains('SETUP02', $symbols);

        // Check top 10 EPS surprises (EPS03 through EPS12)
        for ($i = 3; $i <= 12; $i++) {
            $sym = 'EPS'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->assertContains($sym, $symbols);
        }

        $this->assertNotContains('EPS01', $symbols);
        $this->assertNotContains('EPS02', $symbols);
    }

    public function test_overlapping_symbols_in_setups_and_eps_surprises_are_deduplicated(): void
    {
        // Symbol DUAL is in both top setups and top EPS surprises
        StockBuySetupAlert::create([
            'symbol' => 'DUAL',
            'company_name' => 'Dual Company',
            'exchange' => 'NASDAQ',
            'setup_score' => 99,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        EarningsEvent::create([
            'symbol' => 'DUAL',
            'company_name' => 'Dual Company',
            'exchange' => 'NASDAQ',
            'report_date' => Carbon::now()->toDateString(),
            'eps_surprise_percent' => 50.0,
            'source' => 'test',
        ]);

        $user = User::factory()->create();

        $defaultWatchlist = Watchlist::where('user_id', $user->id)
            ->where('is_default', true)
            ->first();

        $items = $defaultWatchlist->items()->with('stock')->get();
        $this->assertCount(1, $items);
        $this->assertEquals('DUAL', $items->first()->stock->symbol);
    }

    public function test_eps_candidate_ranking_returns_10_distinct_symbols_preferring_recent_positive_surprises(): void
    {
        // Create multiple historical reports for AAPL and MSFT with very high surprises
        for ($q = 1; $q <= 5; $q++) {
            EarningsEvent::create([
                'symbol' => 'AAPL',
                'company_name' => 'Apple Inc',
                'exchange' => 'NASDAQ',
                'report_date' => Carbon::now()->subMonths(6 * (6 - $q))->toDateString(),
                'eps_surprise_percent' => 50.0 + ($q * 10), // latest is 100.0%
                'source' => 'test',
            ]);

            EarningsEvent::create([
                'symbol' => 'MSFT',
                'company_name' => 'Microsoft Corp',
                'exchange' => 'NASDAQ',
                'report_date' => Carbon::now()->subMonths(6 * (6 - $q))->toDateString(),
                'eps_surprise_percent' => 40.0 + ($q * 10), // latest is 90.0%
                'source' => 'test',
            ]);
        }

        // Create 10 other distinct symbols
        for ($i = 1; $i <= 10; $i++) {
            $sym = 'DIST'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            EarningsEvent::create([
                'symbol' => $sym,
                'company_name' => "Distinct Corp {$i}",
                'exchange' => 'NYSE',
                'report_date' => Carbon::now()->subDays(10 - $i)->toDateString(),
                'eps_surprise_percent' => 10.0 + $i,
                'source' => 'test',
            ]);
        }

        $service = app(DashboardQueryService::class);
        $results = $service->highestEpsSurprises(10);

        // Exactly 10 distinct qualifying symbols should be returned
        $this->assertCount(10, $results);
        $symbols = $results->pluck('symbol')->all();
        $this->assertCount(10, array_unique($symbols));

        // AAPL and MSFT should be in the top 10 with their latest events
        $this->assertContains('AAPL', $symbols);
        $this->assertContains('MSFT', $symbols);
        $aaplEvent = $results->firstWhere('symbol', 'AAPL');
        $this->assertEquals(100.0, (float) $aaplEvent->eps_surprise_percent);
    }

    public function test_account_registration_succeeds_and_logs_warning_when_provisioning_throws(): void
    {
        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Failed to provision default watchlist');
            });

        // Mock DefaultWatchlistService to throw an exception
        $mockService = \Mockery::mock(DefaultWatchlistService::class);
        $mockService->shouldReceive('ensureDefaultWatchlist')
            ->andThrow(new \RuntimeException('Service unavailable'));
        $this->app->instance(DefaultWatchlistService::class, $mockService);

        // User creation should succeed without throwing
        $user = User::factory()->create(['email' => 'resilient@example.com']);
        $this->assertNotNull($user->id);
        $this->assertEquals('resilient@example.com', $user->email);
    }

    public function test_existing_nonempty_user_managed_default_watchlist_is_never_populated_or_modified(): void
    {
        $user = User::factory()->create();
        $defaultWatchlist = Watchlist::where('user_id', $user->id)->where('is_default', true)->first();

        // User manually creates a custom stock in their default watchlist
        $customStock = Stock::factory()->create(['symbol' => 'CUSTOM_STOCK']);
        WatchlistItem::create([
            'watchlist_id' => $defaultWatchlist->id,
            'stock_id' => $customStock->id,
            'currency' => 'USD',
            'moat_level' => MoatLevel::Medium->value,
            'notes' => 'User custom notes',
        ]);

        // Alerts and EPS surprises are generated
        StockBuySetupAlert::create([
            'symbol' => 'RECOMMENDED1',
            'company_name' => 'Recommended Co',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        EarningsEvent::create([
            'symbol' => 'RECOMMENDED2',
            'company_name' => 'Recommended EPS Co',
            'exchange' => 'NASDAQ',
            'report_date' => Carbon::now()->toDateString(),
            'eps_surprise_percent' => 50.0,
            'source' => 'test',
        ]);

        // Populate / ensure / backfill runs
        app(DefaultWatchlistService::class)->ensureDefaultWatchlist($user);
        app(DefaultWatchlistService::class)->backfillExistingUsers();

        $items = $defaultWatchlist->fresh()->items()->with('stock')->get();
        $symbols = $items->pluck('stock.symbol')->all();

        // The user's populated watchlist must NEVER be modified; only the custom stock must be present
        $this->assertCount(1, $items);
        $this->assertEquals(['CUSTOM_STOCK'], $symbols);
        $this->assertNotContains('RECOMMENDED1', $symbols);
        $this->assertNotContains('RECOMMENDED2', $symbols);
    }

    public function test_user_who_removes_stocks_from_provisioned_watchlist_does_not_have_them_readded(): void
    {
        // 1. Create setup alert
        StockBuySetupAlert::create([
            'symbol' => 'STOCK_A',
            'company_name' => 'Company A',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        StockBuySetupAlert::create([
            'symbol' => 'STOCK_B',
            'company_name' => 'Company B',
            'exchange' => 'NASDAQ',
            'setup_score' => 90,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        // User registers -> auto-provisioned with STOCK_A and STOCK_B
        $user = User::factory()->create();
        $defaultWatchlist = Watchlist::where('user_id', $user->id)->where('is_default', true)->first();

        $this->assertEquals(2, $defaultWatchlist->items()->count());
        $this->assertNotNull($defaultWatchlist->auto_provisioned_at);

        // User deliberately removes STOCK_A
        $itemToRemove = $defaultWatchlist->items()->whereHas('stock', fn ($q) => $q->where('symbol', 'STOCK_A'))->first();
        $itemToRemove->delete();

        $this->assertEquals(1, $defaultWatchlist->fresh()->items()->count());

        // Subsequent ensureDefaultWatchlist or backfill run must NOT re-add STOCK_A
        app(DefaultWatchlistService::class)->ensureDefaultWatchlist($user);
        app(DefaultWatchlistService::class)->backfillExistingUsers();

        $freshItems = $defaultWatchlist->fresh()->items()->with('stock')->get();
        $this->assertCount(1, $freshItems);
        $this->assertEquals('STOCK_B', $freshItems->first()->stock->symbol);
    }

    public function test_empty_unprovisioned_default_watchlist_is_populated_on_backfill(): void
    {
        $user = User::factory()->create();
        $defaultWatchlist = Watchlist::where('user_id', $user->id)->where('is_default', true)->first();
        $this->assertEquals(0, $defaultWatchlist->items()->count());

        StockBuySetupAlert::create([
            'symbol' => 'LATER_STOCK',
            'company_name' => 'Later Stock Inc',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        app(DefaultWatchlistService::class)->ensureDefaultWatchlist($user);

        $this->assertEquals(1, $defaultWatchlist->fresh()->items()->count());
        $this->assertEquals('LATER_STOCK', $defaultWatchlist->fresh()->items->first()->stock->symbol);
        $this->assertNotNull($defaultWatchlist->fresh()->auto_provisioned_at);
    }

    public function test_eps_candidate_ranking_filters_positive_surprises_and_handles_null_report_dates(): void
    {
        // Negative surprise (miss) -> must be excluded
        EarningsEvent::create([
            'symbol' => 'NEG_MISS',
            'company_name' => 'Miss Corp',
            'exchange' => 'NASDAQ',
            'report_date' => Carbon::now()->toDateString(),
            'eps_surprise_percent' => -25.0,
            'source' => 'test',
        ]);

        // Zero surprise -> must be excluded
        EarningsEvent::create([
            'symbol' => 'ZERO_SURPRISE',
            'company_name' => 'Zero Corp',
            'exchange' => 'NASDAQ',
            'report_date' => Carbon::now()->toDateString(),
            'eps_surprise_percent' => 0.0,
            'source' => 'test',
        ]);

        // Symbol with null report date
        EarningsEvent::create([
            'symbol' => 'NULL_DATE',
            'company_name' => 'Null Date Corp',
            'exchange' => 'NASDAQ',
            'report_date' => null,
            'eps_surprise_percent' => 15.0,
            'source' => 'test',
        ]);

        // Symbol SYM_A with older report date and newer report date
        EarningsEvent::create([
            'symbol' => 'SYM_A',
            'company_name' => 'A Corp Old',
            'exchange' => 'NASDAQ',
            'report_date' => Carbon::now()->subMonths(6)->toDateString(),
            'eps_surprise_percent' => 20.0,
            'source' => 'test',
        ]);

        EarningsEvent::create([
            'symbol' => 'SYM_A',
            'company_name' => 'A Corp New',
            'exchange' => 'NASDAQ',
            'report_date' => Carbon::now()->toDateString(),
            'eps_surprise_percent' => 50.0,
            'source' => 'test',
        ]);

        // Symbol SYM_B with equal surprise percentage but older report date
        EarningsEvent::create([
            'symbol' => 'SYM_B',
            'company_name' => 'B Corp',
            'exchange' => 'NASDAQ',
            'report_date' => Carbon::now()->subDays(5)->toDateString(),
            'eps_surprise_percent' => 50.0,
            'source' => 'test',
        ]);

        $service = app(DashboardQueryService::class);
        $results = $service->highestEpsSurprises(10);

        $symbols = $results->pluck('symbol')->all();

        // Negative and zero surprises must NOT be present
        $this->assertNotContains('NEG_MISS', $symbols);
        $this->assertNotContains('ZERO_SURPRISE', $symbols);

        // Positive surprises must be present
        $this->assertContains('SYM_A', $symbols);
        $this->assertContains('SYM_B', $symbols);
        $this->assertContains('NULL_DATE', $symbols);

        // SYM_A must be deduplicated to its latest event (50.0%)
        $symAEvent = $results->firstWhere('symbol', 'SYM_A');
        $this->assertEquals(50.0, (float) $symAEvent->eps_surprise_percent);

        // SYM_A (newer date) should be ordered before SYM_B (older date)
        $this->assertEquals('SYM_A', $results[0]->symbol);
        $this->assertEquals('SYM_B', $results[1]->symbol);
    }

    public function test_backfill_artisan_command_executes_idempotently_and_displays_summary(): void
    {
        // 1. User with existing populated watchlist (should be skipped/preserved)
        $user1 = User::factory()->create();
        $default1 = Watchlist::where('user_id', $user1->id)->where('is_default', true)->first();
        $customStock = Stock::factory()->create(['symbol' => 'USER1_STOCK']);
        WatchlistItem::create([
            'watchlist_id' => $default1->id,
            'stock_id' => $customStock->id,
            'currency' => 'USD',
            'moat_level' => MoatLevel::Medium->value,
            'notes' => 'custom',
        ]);

        // 2. User with empty watchlist (should be populated)
        $user2 = User::factory()->create();
        $default2 = Watchlist::where('user_id', $user2->id)->where('is_default', true)->first();

        // 3. User with no default watchlist (should be created)
        $user3 = User::factory()->create();
        $user3->watchlists()->delete();

        StockBuySetupAlert::create([
            'symbol' => 'COMMAND_SETUP',
            'company_name' => 'Command Setup Co',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        // Run command
        $exitCode = $this->artisan('watchlists:backfill-default')
            ->expectsOutput('Starting default watchlist backfill...')
            ->expectsOutput('Default watchlist backfill completed successfully.')
            ->run();

        $this->assertEquals(0, $exitCode);

        // User 1 preserved
        $this->assertEquals(1, $default1->fresh()->items()->count());
        $this->assertEquals('USER1_STOCK', $default1->fresh()->items->first()->stock->symbol);

        // User 2 populated
        $this->assertEquals(1, $default2->fresh()->items()->count());
        $this->assertEquals('COMMAND_SETUP', $default2->fresh()->items->first()->stock->symbol);

        // User 3 created and populated
        $default3 = Watchlist::where('user_id', $user3->id)->where('is_default', true)->first();
        $this->assertNotNull($default3);
        $this->assertEquals(1, $default3->items()->count());

        // Run again for idempotency
        $exitCodeSecond = $this->artisan('watchlists:backfill-default')->run();
        $this->assertEquals(0, $exitCodeSecond);
    }

    public function test_user_has_exactly_one_default_watchlist_even_if_multiple_watchlists_exist(): void
    {
        $user = User::factory()->create();
        $user->watchlists()->delete();

        // Create multiple watchlists using raw DB insert with is_default = true to simulate corrupted/legacy multi-default state
        Watchlist::withoutEvents(function () use ($user) {
            Watchlist::create(['user_id' => $user->id, 'name' => 'List 1', 'is_default' => true]);
            Watchlist::create(['user_id' => $user->id, 'name' => 'List 2', 'is_default' => true]);
            Watchlist::create(['user_id' => $user->id, 'name' => 'setup', 'is_default' => true]);
        });

        // Corrupted state: 3 defaults
        $this->assertEquals(3, Watchlist::where('user_id', $user->id)->where('is_default', true)->count());

        app(DefaultWatchlistService::class)->ensureDefaultWatchlist($user);

        // Cleaned state: exactly 1 default
        $defaults = Watchlist::where('user_id', $user->id)->where('is_default', true)->get();
        $this->assertCount(1, $defaults);
    }

    public function test_backfill_does_not_repeat_ranking_queries_per_user(): void
    {
        // Create 5 users without watchlists
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $u = User::factory()->create();
            $u->watchlists()->delete();
            $users[] = $u;
        }

        StockBuySetupAlert::create([
            'symbol' => 'BATCH1',
            'company_name' => 'Batch Co',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        $queryServiceSpy = \Mockery::spy(DashboardQueryService::class);
        $queryServiceSpy->shouldReceive('topScoringSetups')->andReturn(
            app(DashboardQueryService::class)->topScoringSetups(10)
        );
        $queryServiceSpy->shouldReceive('highestEpsSurprises')->andReturn(
            app(DashboardQueryService::class)->highestEpsSurprises(10)
        );

        $service = new DefaultWatchlistService($queryServiceSpy, app(StockProvisioner::class));
        $service->backfillExistingUsers();

        // topScoringSetups and highestEpsSurprises should each be called only ONCE during backfill
        $queryServiceSpy->shouldHaveReceived('topScoringSetups')->once();
        $queryServiceSpy->shouldHaveReceived('highestEpsSurprises')->once();

        // Verify all 5 users received their default watchlist and stock
        foreach ($users as $u) {
            $default = Watchlist::where('user_id', $u->id)->where('is_default', true)->first();
            $this->assertNotNull($default);
            $this->assertEquals(1, $default->items()->count());
        }
    }

    public function test_backfill_migrations_are_idempotent_and_support_rerun(): void
    {
        $user1 = User::factory()->create();
        $user1->watchlists()->delete();

        StockBuySetupAlert::create([
            'symbol' => 'MIGRATE1',
            'company_name' => 'Migrate Co',
            'exchange' => 'NASDAQ',
            'setup_score' => 98,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        // Run Migration 1 (original deployed migration sets oldest default if exists)
        $migration1 = require database_path('migrations/2026_11_10_000001_backfill_default_watchlists_for_existing_users.php');
        $migration1->up();

        // Run Migration 2 (ensures auto_provisioned_at column and backfills existing users)
        $migration2 = require database_path('migrations/2026_11_10_000002_ensure_default_watchlists_and_recommendations_for_existing_users.php');
        $migration2->up();

        $default1 = Watchlist::where('user_id', $user1->id)->where('is_default', true)->first();
        $this->assertNotNull($default1);
        $this->assertEquals(1, $default1->items()->count());
        $this->assertEquals('MIGRATE1', $default1->items->first()->stock->symbol);
        $this->assertNotNull($default1->auto_provisioned_at);

        // Run Migration 3 (ensures auto_provisioned_at and reconciles any unmarked system watchlists)
        $migration3 = require database_path('migrations/2026_11_10_000003_add_auto_provisioned_at_to_watchlists_table.php');
        $migration3->up();

        // Must still have exactly 1 default watchlist and 1 item (no duplicates)
        $defaults = Watchlist::where('user_id', $user1->id)->where('is_default', true)->get();
        $this->assertCount(1, $defaults);
        $this->assertEquals(1, $defaults->first()->items()->count());
        $this->assertEquals('MIGRATE1', $defaults->first()->items->first()->stock->symbol);
        $this->assertNotNull($defaults->first()->auto_provisioned_at);
    }

    public function test_sequential_migrations_establish_provisioning_state_and_preserve_user_watchlists(): void
    {
        // User A: Has no watchlist
        $userA = User::factory()->create();
        $userA->watchlists()->delete();

        // User B: Has empty default watchlist
        $userB = User::factory()->create();
        $userB->watchlists()->delete();
        $defaultB = Watchlist::create([
            'user_id' => $userB->id,
            'name' => 'My Default',
            'is_default' => true,
        ]);

        // User C: Has custom nonempty user-managed default watchlist
        $userC = User::factory()->create();
        $userC->watchlists()->delete();
        $defaultC = Watchlist::create([
            'user_id' => $userC->id,
            'name' => 'Custom Portfolio',
            'description' => 'User created list',
            'is_default' => true,
        ]);
        $customStock = Stock::factory()->create(['symbol' => 'CUSTOM_C']);
        WatchlistItem::create([
            'watchlist_id' => $defaultC->id,
            'stock_id' => $customStock->id,
            'currency' => 'USD',
            'moat_level' => MoatLevel::High->value,
            'notes' => 'Subscriber custom stock',
        ]);

        StockBuySetupAlert::create([
            'symbol' => 'SEQ_STOCK',
            'company_name' => 'Sequence Co',
            'exchange' => 'NASDAQ',
            'setup_score' => 97,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        // Run sequential migrations: 000001 -> 000002 -> 000003
        $m1 = require database_path('migrations/2026_11_10_000001_backfill_default_watchlists_for_existing_users.php');
        $m1->up();

        $m2 = require database_path('migrations/2026_11_10_000002_ensure_default_watchlists_and_recommendations_for_existing_users.php');
        $m2->up();

        $m3 = require database_path('migrations/2026_11_10_000003_add_auto_provisioned_at_to_watchlists_table.php');
        $m3->up();

        // User A received system default watchlist and is marked auto_provisioned
        $watchA = Watchlist::where('user_id', $userA->id)->where('is_default', true)->first();
        $this->assertNotNull($watchA);
        $this->assertEquals('setup', $watchA->name);
        $this->assertNotNull($watchA->auto_provisioned_at);
        $this->assertEquals(1, $watchA->items()->count());

        // User B's empty default watchlist was populated and marked auto_provisioned
        $watchB = $defaultB->fresh();
        $this->assertNotNull($watchB->auto_provisioned_at);
        $this->assertEquals(1, $watchB->items()->count());

        // User C's populated watchlist was NOT modified and NOT marked as system auto-provisioned
        $watchC = $defaultC->fresh();
        $this->assertNull($watchC->auto_provisioned_at);
        $this->assertEquals(1, $watchC->items()->count());
        $this->assertEquals('CUSTOM_C', $watchC->items->first()->stock->symbol);
    }

    public function test_complete_subscriber_deletion_of_auto_generated_stocks_is_preserved(): void
    {
        StockBuySetupAlert::create([
            'symbol' => 'AUTO_1',
            'company_name' => 'Auto 1',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        // User registers and gets auto-provisioned
        $user = User::factory()->create();
        $watchlist = Watchlist::where('user_id', $user->id)->where('is_default', true)->first();
        $this->assertNotNull($watchlist->auto_provisioned_at);
        $this->assertEquals(1, $watchlist->items()->count());

        // Subscriber deliberately clears their watchlist completely (deletes all items)
        $watchlist->items()->delete();
        $this->assertEquals(0, $watchlist->fresh()->items()->count());

        // Backfill runs again
        app(DefaultWatchlistService::class)->ensureDefaultWatchlist($user);
        app(DefaultWatchlistService::class)->backfillExistingUsers();
        $this->artisan('watchlists:backfill-default')->run();

        // The watchlist must remain empty! Recommendations must not be re-added.
        $this->assertEquals(0, $watchlist->fresh()->items()->count());
    }

    public function test_failed_stock_resolution_rolls_back_transaction_and_records_failure(): void
    {
        StockBuySetupAlert::create([
            'symbol' => 'FAIL_STOCK',
            'company_name' => 'Fail Co',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        $failingProvisioner = \Mockery::mock(StockProvisioner::class);
        $failingProvisioner->shouldReceive('findOrCreate')
            ->andThrow(new \RuntimeException('Stock API gateway timed out'));

        $service = new DefaultWatchlistService(app(DashboardQueryService::class), $failingProvisioner);

        $user = User::factory()->create();
        $user->watchlists()->delete();

        // Run backfill with the failing provisioner
        $stats = $service->backfillExistingUsers($user->id, true);

        // Failure should be observable in metrics
        $this->assertEquals(1, $stats['failed']);
        $this->assertEquals(0, $stats['created']);
        $this->assertEquals(0, $stats['populated']);

        // No unprovisioned or partial watchlist should be marked as complete
        $watchlist = Watchlist::where('user_id', $user->id)->where('is_default', true)->first();
        $this->assertTrue($watchlist === null || $watchlist->auto_provisioned_at === null);

        // Now run with normal provisioner -> recovery succeeds
        $normalService = app(DefaultWatchlistService::class);
        $recoveryStats = $normalService->backfillExistingUsers($user->id, true);

        $this->assertEquals(0, $recoveryStats['failed']);
        $this->assertEquals(1, $recoveryStats['created']);

        $recoveredWatchlist = Watchlist::where('user_id', $user->id)->where('is_default', true)->first();
        $this->assertNotNull($recoveredWatchlist);
        $this->assertNotNull($recoveredWatchlist->auto_provisioned_at);
        $this->assertEquals(1, $recoveredWatchlist->items()->count());
    }

    public function test_eps_ranking_ignores_stale_positive_beats_when_newer_event_is_negative_or_miss(): void
    {
        // STALE_BEAT: Old beat (+80% on Jan 1) but New miss (-25% on Jun 1) -> MUST NOT BE RETURNED
        EarningsEvent::create([
            'symbol' => 'STALE_BEAT',
            'company_name' => 'Stale Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-01-01',
            'eps_surprise_percent' => 80.0,
            'source' => 'test',
        ]);
        EarningsEvent::create([
            'symbol' => 'STALE_BEAT',
            'company_name' => 'Stale Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-06-01',
            'eps_surprise_percent' => -25.0,
            'source' => 'test',
        ]);

        // RECENT_BEAT: Old miss (-10% on Jan 1) but New beat (+40% on Jun 1) -> MUST BE RETURNED (+40%)
        EarningsEvent::create([
            'symbol' => 'RECENT_BEAT',
            'company_name' => 'Recent Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-01-01',
            'eps_surprise_percent' => -10.0,
            'source' => 'test',
        ]);
        EarningsEvent::create([
            'symbol' => 'RECENT_BEAT',
            'company_name' => 'Recent Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-06-01',
            'eps_surprise_percent' => 40.0,
            'source' => 'test',
        ]);

        // DOUBLE_BEAT: Old beat (+10% on Jan 1) and New beat (+30% on Jun 1) -> MUST BE RETURNED ONCE (+30%)
        EarningsEvent::create([
            'symbol' => 'DOUBLE_BEAT',
            'company_name' => 'Double Beat Co',
            'exchange' => 'NYSE',
            'report_date' => '2026-01-01',
            'eps_surprise_percent' => 10.0,
            'source' => 'test',
        ]);
        EarningsEvent::create([
            'symbol' => 'DOUBLE_BEAT',
            'company_name' => 'Double Beat Co',
            'exchange' => 'NYSE',
            'report_date' => '2026-06-01',
            'eps_surprise_percent' => 30.0,
            'source' => 'test',
        ]);

        // STILL_BEAT: Single positive event (+20% on Mar 1) -> MUST BE RETURNED (+20%)
        EarningsEvent::create([
            'symbol' => 'STILL_BEAT',
            'company_name' => 'Still Beat Co',
            'exchange' => 'NYSE',
            'report_date' => '2026-03-01',
            'eps_surprise_percent' => 20.0,
            'source' => 'test',
        ]);

        $results = app(DashboardQueryService::class)->highestEpsSurprises(10);
        $symbols = $results->pluck('symbol')->all();

        // Stale beat must NOT be present because its most recent earnings was negative (-25%)
        $this->assertNotContains('STALE_BEAT', $symbols);

        // Recent beat must be present
        $this->assertContains('RECENT_BEAT', $symbols);
        $this->assertEquals(40.0, (float) $results->firstWhere('symbol', 'RECENT_BEAT')->eps_surprise_percent);

        // Double beat must be present once with its latest value (30%)
        $this->assertContains('DOUBLE_BEAT', $symbols);
        $this->assertEquals(30.0, (float) $results->firstWhere('symbol', 'DOUBLE_BEAT')->eps_surprise_percent);

        // Still beat must be present
        $this->assertContains('STILL_BEAT', $symbols);
        $this->assertEquals(20.0, (float) $results->firstWhere('symbol', 'STILL_BEAT')->eps_surprise_percent);
    }

    public function test_eps_ranking_selects_latest_completed_result_when_upcoming_event_has_null_surprise(): void
    {
        // UPCOMING_BEAT: Completed beat (+35% on Jun 1) and upcoming future event (Nov 20 with NULL surprise) -> MUST BE RETURNED (+35%)
        EarningsEvent::create([
            'symbol' => 'UPCOMING_BEAT',
            'company_name' => 'Upcoming Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-06-01',
            'eps_surprise_percent' => 35.0,
            'source' => 'test',
        ]);
        EarningsEvent::create([
            'symbol' => 'UPCOMING_BEAT',
            'company_name' => 'Upcoming Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-11-20',
            'eps_surprise_percent' => null,
            'source' => 'test',
        ]);

        // UPCOMING_MISS: Completed miss (-15% on Jun 1) and upcoming event (Nov 20 with NULL surprise) -> MUST NOT BE RETURNED
        EarningsEvent::create([
            'symbol' => 'UPCOMING_MISS',
            'company_name' => 'Upcoming Miss Co',
            'exchange' => 'NYSE',
            'report_date' => '2026-06-01',
            'eps_surprise_percent' => -15.0,
            'source' => 'test',
        ]);
        EarningsEvent::create([
            'symbol' => 'UPCOMING_MISS',
            'company_name' => 'Upcoming Miss Co',
            'exchange' => 'NYSE',
            'report_date' => '2026-11-20',
            'eps_surprise_percent' => null,
            'source' => 'test',
        ]);

        // UPCOMING_STALE_BEAT: Old beat (+50% on Jan 1), Recent miss (-20% on Jun 1), Upcoming event (Nov 20 with NULL surprise) -> MUST NOT BE RETURNED
        EarningsEvent::create([
            'symbol' => 'UPCOMING_STALE_BEAT',
            'company_name' => 'Upcoming Stale Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-01-01',
            'eps_surprise_percent' => 50.0,
            'source' => 'test',
        ]);
        EarningsEvent::create([
            'symbol' => 'UPCOMING_STALE_BEAT',
            'company_name' => 'Upcoming Stale Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-06-01',
            'eps_surprise_percent' => -20.0,
            'source' => 'test',
        ]);
        EarningsEvent::create([
            'symbol' => 'UPCOMING_STALE_BEAT',
            'company_name' => 'Upcoming Stale Beat Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-11-20',
            'eps_surprise_percent' => null,
            'source' => 'test',
        ]);

        // UPCOMING_ONLY: Only an upcoming event (Nov 20 with NULL surprise) -> MUST NOT BE RETURNED
        EarningsEvent::create([
            'symbol' => 'UPCOMING_ONLY',
            'company_name' => 'Upcoming Only Co',
            'exchange' => 'NASDAQ',
            'report_date' => '2026-11-20',
            'eps_surprise_percent' => null,
            'source' => 'test',
        ]);

        $results = app(DashboardQueryService::class)->highestEpsSurprises(10);
        $symbols = $results->pluck('symbol')->all();

        // Upcoming beat should be returned using its latest completed result
        $this->assertContains('UPCOMING_BEAT', $symbols);
        $this->assertEquals(35.0, (float) $results->firstWhere('symbol', 'UPCOMING_BEAT')->eps_surprise_percent);

        // Negative completed result should not be returned
        $this->assertNotContains('UPCOMING_MISS', $symbols);

        // Stale beat should not be returned because the latest completed result was a miss
        $this->assertNotContains('UPCOMING_STALE_BEAT', $symbols);

        // Upcoming only should not be returned
        $this->assertNotContains('UPCOMING_ONLY', $symbols);
    }

    public function test_backfill_command_reports_failures_accurately_and_exits_with_failure_status(): void
    {
        StockBuySetupAlert::create([
            'symbol' => 'FAIL_CMD',
            'company_name' => 'Fail Cmd Co',
            'exchange' => 'NASDAQ',
            'setup_score' => 95,
            'setup_type' => 'vcp_breakout',
            'spike_date' => Carbon::now()->toDateString(),
            'source' => 'test',
        ]);

        $failingService = \Mockery::mock(DefaultWatchlistService::class);
        $failingService->shouldReceive('backfillExistingUsers')
            ->once()
            ->andReturn([
                'total' => 1,
                'created' => 0,
                'populated' => 0,
                'skipped' => 0,
                'failed' => 1,
            ]);

        $this->app->instance(DefaultWatchlistService::class, $failingService);

        $exitCode = $this->artisan('watchlists:backfill-default')
            ->expectsOutput('Starting default watchlist backfill...')
            ->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_backfill_command_exits_with_failure_on_unexpected_exception(): void
    {
        $failingService = \Mockery::mock(DefaultWatchlistService::class);
        $failingService->shouldReceive('backfillExistingUsers')
            ->once()
            ->andThrow(new \RuntimeException('Fatal DB connection error during backfill'));

        $this->app->instance(DefaultWatchlistService::class, $failingService);

        $exitCode = $this->artisan('watchlists:backfill-default')
            ->expectsOutput('Starting default watchlist backfill...')
            ->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_anonymous_user_is_not_given_a_default_watchlist(): void
    {
        $anon = User::ensureAnonymousUser();

        $count = Watchlist::where('user_id', $anon->id)->count();
        $this->assertEquals(0, $count);

        app(DefaultWatchlistService::class)->ensureDefaultWatchlist($anon);

        $this->assertEquals(0, Watchlist::where('user_id', $anon->id)->count());
    }
}
