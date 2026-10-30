<?php

use App\Enums\UserRole;
use App\Models\EarningsEvent;
use App\Models\SectorFlowSnapshot;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;

test('guests are redirected to the login page', function () {
    $response = $this->get('/dashboard');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
});

test('stock mode dashboard displays empty watchlist notice when user has no watchlists', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertSee('Market Overview')
        ->assertSee('Your watchlist is currently empty or does not exist.')
        ->assertSee(route('watchlists.index'));
});

test('stock mode dashboard displays empty watchlist notice when user watchlist has no items', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    Watchlist::create([
        'user_id' => $user->id,
        'name' => 'Tech Focus',
        'is_default' => true,
    ]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertSee('Your watchlist is currently empty or does not exist.')
        ->assertSee(route('watchlists.index'));
});

test('stock mode dashboard displays top 2 scoring setups market-wide', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);

    $defaultWatchlist = Watchlist::create([
        'user_id' => $user->id,
        'name' => 'Core Equities',
        'is_default' => true,
    ]);

    $otherWatchlist = Watchlist::create([
        'user_id' => $user->id,
        'name' => 'Secondary List',
        'is_default' => false,
    ]);

    $stockA = Stock::factory()->create(['symbol' => 'ALPH', 'company_name' => 'Alpha Corp']);
    $stockB = Stock::factory()->create(['symbol' => 'BETA', 'company_name' => 'Beta Inc']);
    $stockC = Stock::factory()->create(['symbol' => 'GAMM', 'company_name' => 'Gamma LLC']);
    $stockD = Stock::factory()->create(['symbol' => 'DELT', 'company_name' => 'Delta Group']);

    WatchlistItem::factory()->create(['watchlist_id' => $defaultWatchlist->id, 'stock_id' => $stockA->id]);
    WatchlistItem::factory()->create(['watchlist_id' => $defaultWatchlist->id, 'stock_id' => $stockB->id]);
    WatchlistItem::factory()->create(['watchlist_id' => $defaultWatchlist->id, 'stock_id' => $stockC->id]);
    WatchlistItem::factory()->create(['watchlist_id' => $otherWatchlist->id, 'stock_id' => $stockD->id]);

    StockBuySetupAlert::create([
        'symbol' => 'ALPH',
        'company_name' => 'Alpha Corp',
        'setup_score' => 95,
        'setup_type' => 'vcp_breakout',
        'spike_date' => '2026-10-20',
        'source' => 'test',
    ]);
    StockBuySetupAlert::create([
        'symbol' => 'BETA',
        'company_name' => 'Beta Inc',
        'setup_score' => 88,
        'setup_type' => 'heartbeat_spike',
        'spike_date' => '2026-10-21',
        'source' => 'test',
    ]);
    StockBuySetupAlert::create([
        'symbol' => 'GAMM',
        'company_name' => 'Gamma LLC',
        'setup_score' => 70,
        'setup_type' => 'range_compression',
        'spike_date' => '2026-10-19',
        'source' => 'test',
    ]);
    StockBuySetupAlert::create([
        'symbol' => 'DELT',
        'company_name' => 'Delta Group',
        'setup_score' => 99,
        'setup_type' => 'vcp_breakout',
        'spike_date' => '2026-10-22',
        'source' => 'test',
    ]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertSee('The Top Scoring Setups')
        ->assertSee('ALPH')
        ->assertSee('Alpha Corp')
        ->assertSee('95')
        ->assertSee('DELT')
        ->assertSee('Delta Group')
        ->assertSee('99')
        ->assertDontSee('Beta Inc')
        ->assertDontSee('Gamma LLC');
});

test('stock mode dashboard falls back to first watchlist when no default watchlist is set', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);

    $firstWatchlist = Watchlist::create([
        'user_id' => $user->id,
        'name' => 'First Watchlist',
        'is_default' => false,
    ]);

    $stockA = Stock::factory()->create(['symbol' => 'AAA', 'company_name' => 'Triple A Corp']);
    WatchlistItem::factory()->create(['watchlist_id' => $firstWatchlist->id, 'stock_id' => $stockA->id]);

    StockBuySetupAlert::create([
        'symbol' => 'AAA',
        'company_name' => 'Triple A Corp',
        'setup_score' => 91,
        'setup_type' => 'vcp_breakout',
        'spike_date' => '2026-10-20',
        'source' => 'test',
    ]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertSee('The Top Scoring Setups')
        ->assertSee('AAA')
        ->assertSee('91');
});

test('stock mode dashboard displays top 2 highest percentage gaining stocks since date added to watchlist', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);

    $watchlist = Watchlist::create([
        'user_id' => $user->id,
        'name' => 'Momentum Watchlist',
        'is_default' => true,
    ]);

    $stockA = Stock::factory()->create(['symbol' => 'WIN1', 'company_name' => 'Winner One']);
    $stockB = Stock::factory()->create(['symbol' => 'WIN2', 'company_name' => 'Winner Two']);
    $stockC = Stock::factory()->create(['symbol' => 'LAG1', 'company_name' => 'Laggard One']);

    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stockA->id, 'created_at' => now()->subDays(30)]);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stockB->id, 'created_at' => now()->subDays(20)]);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stockC->id, 'created_at' => now()->subDays(10)]);

    $fakeProvider = new FakeMarketDataProvider();
    $fakeProvider->dailyBars['WIN1'] = [
        ['date' => now()->subDays(30)->toDateString(), 'close' => 10.0],
        ['date' => now()->toDateString(), 'close' => 15.0],
    ];
    $fakeProvider->dailyBars['WIN2'] = [
        ['date' => now()->subDays(20)->toDateString(), 'close' => 20.0],
        ['date' => now()->toDateString(), 'close' => 26.0],
    ];
    $fakeProvider->dailyBars['LAG1'] = [
        ['date' => now()->subDays(10)->toDateString(), 'close' => 50.0],
        ['date' => now()->toDateString(), 'close' => 51.0],
    ];

    app()->instance(MarketDataProvider::class, $fakeProvider);

    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertSee('Top Watchlist Gainers')
        ->assertSee('WIN1')
        ->assertSee('+50.00%')
        ->assertSee('WIN2')
        ->assertSee('+30.00%')
        ->assertDontSee('LAG1');
});

test('stock mode dashboard displays top 2 money flow gainers and losers in the past 24 hours', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);

    SectorFlowSnapshot::factory()->create([
        'sector' => 'technology',
        'label' => 'Technology',
        'daily_change_pct' => 3.50,
        'direction' => 'accelerating',
        'interval' => 'hourly',
        'captured_at' => now(),
    ]);
    SectorFlowSnapshot::factory()->create([
        'sector' => 'financials',
        'label' => 'Financials',
        'daily_change_pct' => 2.10,
        'direction' => 'leading',
        'interval' => 'hourly',
        'captured_at' => now(),
    ]);
    SectorFlowSnapshot::factory()->create([
        'sector' => 'healthcare',
        'label' => 'Healthcare',
        'daily_change_pct' => 0.40,
        'direction' => 'improving',
        'interval' => 'hourly',
        'captured_at' => now(),
    ]);
    SectorFlowSnapshot::factory()->create([
        'sector' => 'real_estate',
        'label' => 'Real Estate',
        'daily_change_pct' => -2.80,
        'direction' => 'cooling',
        'interval' => 'hourly',
        'captured_at' => now(),
    ]);
    SectorFlowSnapshot::factory()->create([
        'sector' => 'utilities',
        'label' => 'Utilities',
        'daily_change_pct' => -4.10,
        'direction' => 'weakening',
        'interval' => 'hourly',
        'captured_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertSee('Money Flow Gainers')
        ->assertSee('Technology')
        ->assertSee('+3.50%')
        ->assertSee('Financials')
        ->assertSee('+2.10%')
        ->assertSee('Money Flow Losers')
        ->assertSee('Utilities')
        ->assertSee('-4.10%')
        ->assertSee('Real Estate')
        ->assertSee('-2.80%')
        ->assertSee('Sector ETF proxy');
});

test('stock mode dashboard displays top 2 highest EPS surprise stocks', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);

    EarningsEvent::create([
        'symbol' => 'BEAT1',
        'company_name' => 'Monster Beat Corp',
        'eps_surprise_percent' => '55.5',
        'eps_actual' => '2.50',
        'eps_estimated' => '1.60',
        'report_date' => '2026-10-25',
        'source' => 'test',
    ]);
    EarningsEvent::create([
        'symbol' => 'BEAT2',
        'company_name' => 'Second Beat Inc',
        'eps_surprise_percent' => '42.0',
        'eps_actual' => '1.20',
        'eps_estimated' => '0.84',
        'report_date' => '2026-10-24',
        'source' => 'test',
    ]);
    EarningsEvent::create([
        'symbol' => 'BEAT3',
        'company_name' => 'Third Beat LLC',
        'eps_surprise_percent' => '12.3',
        'eps_actual' => '0.50',
        'eps_estimated' => '0.45',
        'report_date' => '2026-10-23',
        'source' => 'test',
    ]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertSee('Highest EPS Surprises')
        ->assertSee('BEAT1')
        ->assertSee('+55.5%')
        ->assertSee('Monster Beat Corp')
        ->assertSee('BEAT2')
        ->assertSee('+42.0%')
        ->assertSee('Second Beat Inc')
        ->assertDontSee('BEAT3');
});

test('dashboard respects crypto view mode and renders crypto dashboard instead of stock dashboard', function () {
    $user = User::factory()->create([
        'role' => UserRole::CRYPTO,
        'view_mode' => 'crypto',
    ]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200)
        ->assertDontSee('Market Overview')
        ->assertDontSee('The Top Scoring Setups')
        ->assertDontSee('Money Flow Gainers');
});


function makeAlert(string $symbol, int $score, string $type, string $spikeDate, ?string $company = null, string $source = 'test'): StockBuySetupAlert
{
    return StockBuySetupAlert::create([
        'symbol' => $symbol,
        'company_name' => $company ?? "{$symbol} Corp",
        'setup_score' => $score,
        'setup_type' => $type,
        'spike_date' => $spikeDate,
        'source' => $source,
    ]);
}

test('top scoring setups ignore superseded older scores of the same setup type', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);

    makeAlert('ARVN', 98, 'vcp_breakout', '2026-08-30');
    makeAlert('ARVN', 65, 'vcp_breakout', '2026-10-25');
    makeAlert('OTHR', 70, 'vcp_breakout', '2026-10-20');

    $this->actingAs($user);

    $setups = app(\App\Services\Dashboard\DashboardQueryService::class)->topScoringSetups();

    expect($setups->pluck('symbol')->all())->toBe(['OTHR', 'ARVN'])
        ->and($setups->firstWhere('symbol', 'ARVN')['setup_score'])->toBe(65);
});

test('top scoring setups pick the highest score among the latest alerts of each setup type', function () {
    makeAlert('ARVN', 98, 'vcp_breakout', '2026-08-30');
    makeAlert('ARVN', 65, 'vcp_breakout', '2026-10-25');
    makeAlert('ARVN', 80, 'range_compression', '2026-10-01');
    makeAlert('ZZZZ', 75, 'vcp_breakout', '2026-10-10');
    makeAlert('YYYY', 60, 'vcp_breakout', '2026-10-10');

    $setups = app(\App\Services\Dashboard\DashboardQueryService::class)->topScoringSetups();

    expect($setups)->toHaveCount(2)
        ->and($setups[0]['symbol'])->toBe('ARVN')
        ->and($setups[0]['setup_score'])->toBe(80)
        ->and($setups[0]['setup_type'])->toBe('range_compression')
        ->and($setups[1]['symbol'])->toBe('ZZZZ');
});

test('same-day detections resolve by highest id', function () {
    makeAlert('SAME', 90, 'vcp_breakout', '2026-10-25');
    makeAlert('SAME', 55, 'vcp_breakout', '2026-10-25', null, 'rescan');

    $setups = app(\App\Services\Dashboard\DashboardQueryService::class)->topScoringSetups();

    expect($setups)->toHaveCount(1)->and($setups[0]['setup_score'])->toBe(55);
});

test('market-wide setups render even when the user has an empty watchlist', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    makeAlert('ARVN', 98, 'vcp_breakout', '2026-08-30');
    makeAlert('ARVN', 65, 'vcp_breakout', '2026-10-25');

    $this->actingAs($user)->get('/dashboard')
        ->assertStatus(200)
        ->assertSee('The Top Scoring Setups')
        ->assertSee('ARVN')
        ->assertSee('65')
        ->assertDontSee('Score: 98')
        ->assertSee('Your watchlist is currently empty or does not exist.')
        ->assertDontSee('Top Watchlist Setups');
});

test('top scoring setups are cached until the setup scan invalidates them', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    makeAlert('ARVN', 60, 'vcp_breakout', '2026-10-25');

    $this->actingAs($user)->get('/dashboard')->assertSee('ARVN');
    expect(Cache::has('dash_top_scoring_setups'))->toBeTrue();

    makeAlert('NEWW', 99, 'vcp_breakout', '2026-10-26', 'Newly Scanned Corp');
    $this->get('/dashboard')->assertDontSee('Newly Scanned Corp');

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->zeroOrMoreTimes();
    $mockProvider->shouldReceive('lastErrors')->zeroOrMoreTimes()->andReturn([]);
    $mockProvider->shouldReceive('companyScreener')->zeroOrMoreTimes()->andReturn([]);
    $this->app->instance(MarketDataProvider::class, $mockProvider);

    $this->artisan('stocks:scan-buy-setups', ['--sync' => true])->assertSuccessful();
    expect(Cache::has('dash_top_scoring_setups'))->toBeFalse();

    $this->get('/dashboard')->assertSee('Newly Scanned Corp');
});

test('eps surprises and money flows caches are cleared by their invalidators and refilled on visit', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $cache = app(\App\Services\Dashboard\DashboardCacheService::class);

    $this->actingAs($user)->get('/dashboard')->assertStatus(200);

    expect(Cache::has('dash_highest_eps_surprises'))->toBeTrue()
        ->and(Cache::has('dash_money_flows_gainers'))->toBeTrue()
        ->and(Cache::has('dash_money_flows_losers'))->toBeTrue();

    $cache->invalidateEpsSurprises();
    expect(Cache::has('dash_highest_eps_surprises'))->toBeFalse()
        ->and(Cache::has('dash_money_flows_gainers'))->toBeTrue();

    $cache->invalidateMoneyFlows();
    expect(Cache::has('dash_money_flows_gainers'))->toBeFalse()
        ->and(Cache::has('dash_money_flows_losers'))->toBeFalse();

    $this->get('/dashboard')->assertStatus(200);
    expect(Cache::has('dash_highest_eps_surprises'))->toBeTrue()
        ->and(Cache::has('dash_money_flows_gainers'))->toBeTrue();
});

test('user watchlist gainers are cached per user and cleared when the watchlist changes', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $watchlist = Watchlist::create(['user_id' => $user->id, 'name' => 'Main', 'is_default' => true]);
    $stock = Stock::factory()->create(['symbol' => 'GAIN']);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);

    $this->actingAs($user)->get('/dashboard')->assertStatus(200);
    expect(Cache::has("dash_user_{$user->id}_gainers"))->toBeTrue();

    $other = Stock::factory()->create(['symbol' => 'MORE']);
    WatchlistItem::factory()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $other->id]);
    expect(Cache::has("dash_user_{$user->id}_gainers"))->toBeFalse();
});

test('earnings surprise scan completion clears the cached EPS surprises card', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES, 'view_mode' => 'stocks']);
    $this->actingAs($user)->get('/dashboard')->assertStatus(200);
    expect(Cache::has('dash_highest_eps_surprises'))->toBeTrue();

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('clearErrors')->zeroOrMoreTimes();
    $mockProvider->shouldReceive('lastErrors')->zeroOrMoreTimes()->andReturn([]);
    $mockProvider->shouldReceive('earningsSurprises')->zeroOrMoreTimes()->andReturn([]);
    $mockProvider->shouldReceive('earningsCalendar')->zeroOrMoreTimes()->andReturn([]);
    $this->app->instance(MarketDataProvider::class, $mockProvider);

    $this->artisan('earnings:scan-surprises')->assertSuccessful();

    expect(Cache::has('dash_highest_eps_surprises'))->toBeFalse();
});
