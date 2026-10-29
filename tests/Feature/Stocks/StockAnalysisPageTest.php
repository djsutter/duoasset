<?php

use App\Livewire\Stocks\Show as StockShow;
use App\Models\Currency;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Illuminate\Support\Facades\Cache::flush();

    // Seed CAD currency which layout/widgets depend on
    Currency::factory()->create([
        'currency_code' => 'CAD',
        'name' => 'Canadian Dollar',
        'symbol' => '$',
        'scale' => 2,
        'display_scale' => 2,
        'type' => 'fiat',
        'is_active' => true,
    ]);
});

test('unauthenticated users are redirected to login when accessing stock page', function () {
    $response = $this->get('/stocks/ARVN');
    $response->assertRedirect(route('login'));
});

test('authenticated user can view stock analysis page for standard symbols', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->profiles['ARVN'] = [
        'symbol' => 'ARVN',
        'company_name' => 'Arvinas Inc',
        'exchange' => 'NASDAQ',
        'currency' => 'USD',
        'market_cap' => 1500000000,
    ];
    $fake->quotes['ARVN'] = [
        'symbol' => 'ARVN',
        'price' => 28.50,
        'daily_change' => 1.25,
        'daily_change_percent' => 4.58,
        'volume' => 850000,
        'market_cap' => 1500000000,
    ];
    $fake->dailyBars['ARVN'] = [
        ['date' => '2026-10-24', 'open' => 26.50, 'high' => 27.50, 'low' => 26.10, 'close' => 27.25, 'volume' => 700000],
        ['date' => '2026-10-25', 'open' => 27.30, 'high' => 28.60, 'low' => 27.00, 'close' => 28.50, 'volume' => 850000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN');
    $response->assertOk();
    $response->assertSee('ARVN');
    $response->assertSee('Arvinas Inc');
    $response->assertSee('28.50');
});

test('symbols with periods like FEO.V are handled correctly', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->profiles['FEO.V'] = [
        'symbol' => 'FEO.V',
        'company_name' => 'Feo Minerals Corp',
        'exchange' => 'TSXV',
        'currency' => 'CAD',
        'market_cap' => 45000000,
    ];
    $fake->quotes['FEO.V'] = [
        'symbol' => 'FEO.V',
        'price' => 0.85,
        'daily_change' => 0.05,
        'daily_change_percent' => 6.25,
        'volume' => 120000,
        'market_cap' => 45000000,
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/FEO.V');
    $response->assertOk();
    $response->assertSee('FEO.V');
    $response->assertSee('Feo Minerals Corp');
});

test('invalid symbols are safely rejected with 404', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Invalid symbols containing spaces, slashes, or script injection
    $this->actingAs($user)->get('/stocks/INVALID%20SYMBOL')->assertNotFound();
    $this->actingAs($user)->get('/stocks/<script>')->assertNotFound();
});

test('candles json endpoint returns normalized OHLCV schema and sorted timestamps', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['RARE'] = [
        ['date' => '2026-10-20', 'open' => 45.0, 'high' => 46.5, 'low' => 44.8, 'close' => 46.0, 'volume' => 500000],
        ['date' => '2026-10-21', 'open' => 46.2, 'high' => 47.0, 'low' => 45.9, 'close' => 46.8, 'volume' => 550000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/RARE/candles?interval=1day');
    $response->assertOk();
    $response->assertJsonStructure([
        'symbol',
        'interval',
        'candles' => [
            '*' => ['time', 'open', 'high', 'low', 'close', 'volume'],
        ],
    ]);

    $data = $response->json();
    expect($data['symbol'])->toBe('RARE');
    expect($data['interval'])->toBe('1day');
    expect($data['candles'])->toHaveCount(2);
    // Ensure chronological sorting
    expect($data['candles'][0]['time'])->toBeLessThan($data['candles'][1]['time']);
    expect($data['candles'][0]['close'])->toEqual(46.0);
    expect($data['candles'][1]['close'])->toEqual(46.8);
});

test('candles json endpoint supports dotted symbols', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['FEO.V'] = [
        ['date' => '2026-10-20', 'open' => 0.80, 'high' => 0.88, 'low' => 0.79, 'close' => 0.85, 'volume' => 100000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/FEO.V/candles');
    $response->assertOk();
    $data = $response->json();
    expect($data['symbol'])->toBe('FEO.V');
    expect($data['candles'])->toHaveCount(1);
});

test('empty or failing API responses do not crash the page and render unavailable state', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Provider returns nothing for this symbol
    $fake = new FakeMarketDataProvider;
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/GPUS');
    $response->assertOk();
    $response->assertSee('GPUS');
    $response->assertSeeHtml('data-testid="setup-score-unavailable"');
});

test('setup score panel displays existing persisted score from StockBuySetupAlert', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    // Create a setup alert record
    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'company_name' => 'Arvinas Inc',
        'exchange' => 'NASDAQ',
        'price' => 28.50,
        'setup_type' => 'vcp_breakout',
        'setup_score' => 88,
        'raw_setup_score' => 92.5,
        'heartbeat_score' => 84.0,
        'spike_date' => '2026-10-15',
        'spike_volume' => 1500000,
        'base_duration_days' => 45,
        'range_compression_pct' => 4.2,
        'reason_summary' => 'Clean 45-day VCP consolidation with 4.2% range contraction.',
        'status' => 'active',
        'detected_at' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test(StockShow::class, ['symbol' => 'ARVN'])
        ->assertSeeHtml('data-testid="setup-score-panel"')
        ->assertSeeHtml('data-testid="overall-setup-score"')
        ->assertSee('88')
        ->assertSee('vcp_breakout')
        ->assertSee('45 days')
        ->assertSee('4.2%')
        ->assertSee('Clean 45-day VCP consolidation');
});

test('representative symbols ARVN, RARE, GPUS, and FEO.V can all be accessed safely', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    $fake->quotes['RARE'] = ['symbol' => 'RARE', 'price' => 48.00];
    $fake->quotes['GPUS'] = ['symbol' => 'GPUS', 'price' => 5.20];
    $fake->quotes['FEO.V'] = ['symbol' => 'FEO.V', 'price' => 0.85];
    app()->instance(MarketDataProvider::class, $fake);

    foreach (['ARVN', 'RARE', 'GPUS', 'FEO.V'] as $sym) {
        $this->actingAs($user)->get("/stocks/{$sym}")->assertOk()->assertSee($sym);
    }
});

test('ticker link component renders valid link to stocks.show', function () {
    $view = $this->blade('<x-ticker-link symbol="ARVN" />');
    expect((string) $view)->toContain(route('stocks.show', ['symbol' => 'ARVN']));
    expect((string) $view)->toContain('ARVN');

    // Dotted symbol
    $dottedView = $this->blade('<x-ticker-link symbol="FEO.V" />');
    expect((string) $dottedView)->toContain(route('stocks.show', ['symbol' => 'FEO.V']));
    expect((string) $dottedView)->toContain('FEO.V');
});

test('chart page renders interactive interval controls, range controls, and state containers', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN');
    $response->assertOk();

    // Assert Interval controls and all buttons
    $response->assertSeeHtml('data-testid="interval-controls"');
    $response->assertSeeHtml('data-testid="intraday-intervals"');
    $response->assertSeeHtml('data-testid="daily-intervals"');
    foreach (['1m', '5m', '15m', '30m', '1h', '1D', '1W', '1M'] as $interval) {
        $response->assertSeeHtml("data-testid=\"interval-btn-{$interval}\"");
    }

    // Assert Range controls and all buttons
    $response->assertSeeHtml('data-testid="range-controls"');
    foreach (['1M', '3M', '6M', '1Y', '5Y', 'All'] as $range) {
        $response->assertSeeHtml("data-testid=\"range-btn-{$range}\"");
    }

    // Assert Loading indicator, Fullscreen button, and State overlays
    $response->assertSeeHtml('data-testid="chart-loading-indicator"');
    $response->assertSeeHtml('data-testid="chart-fullscreen-btn"');
    $response->assertSeeHtml('data-testid="chart-empty-state"');
    $response->assertSeeHtml('data-testid="chart-error-state"');
});

test('candles json endpoint supports intraday intervals like 1hour and 15min', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->intradayBars['ARVN'] = [
        ['date' => '2026-10-25 14:00:00', 'open' => 28.0, 'high' => 28.5, 'low' => 27.9, 'close' => 28.4, 'volume' => 15000],
        ['date' => '2026-10-25 15:00:00', 'open' => 28.4, 'high' => 28.9, 'low' => 28.2, 'close' => 28.8, 'volume' => 22000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1hour');
    $response->assertOk();

    $data = $response->json();
    expect($data['symbol'])->toBe('ARVN');
    expect($data['interval'])->toBe('1hour');
    expect($data['candles'])->toHaveCount(2);
    expect($data['candles'][0]['time'])->toBeLessThan($data['candles'][1]['time']);
    expect($data['candles'][0]['close'])->toEqual(28.4);
    expect($data['candles'][1]['close'])->toEqual(28.8);
});

test('candles json endpoint aggregates daily bars into weekly and monthly intervals', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['ARVN'] = [
        ['date' => '2026-10-05', 'open' => 20.0, 'high' => 22.0, 'low' => 19.5, 'close' => 21.0, 'volume' => 50000],
        ['date' => '2026-10-06', 'open' => 21.0, 'high' => 23.0, 'low' => 20.5, 'close' => 22.5, 'volume' => 60000],
        ['date' => '2026-10-12', 'open' => 22.5, 'high' => 25.0, 'low' => 22.0, 'close' => 24.0, 'volume' => 70000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    // Test weekly aggregation
    $weeklyResp = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1week');
    $weeklyResp->assertOk();
    $weeklyData = $weeklyResp->json();
    expect($weeklyData['interval'])->toBe('1week');
    expect($weeklyData['candles'])->toHaveCount(2);

    // Test monthly aggregation
    $monthlyResp = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1month');
    $monthlyResp->assertOk();
    $monthlyData = $monthlyResp->json();
    expect($monthlyData['interval'])->toBe('1month');
    expect($monthlyData['candles'])->toHaveCount(1);
    expect($monthlyData['candles'][0]['open'])->toEqual(20.0);
    expect($monthlyData['candles'][0]['high'])->toEqual(25.0);
    expect($monthlyData['candles'][0]['low'])->toEqual(19.5);
    expect($monthlyData['candles'][0]['close'])->toEqual(24.0);
    expect($monthlyData['candles'][0]['volume'])->toEqual(180000.0);
});

test('candles json endpoint supports range parameter shorthand', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['ARVN'] = [
        ['date' => '2026-10-20', 'open' => 25.0, 'high' => 26.0, 'low' => 24.5, 'close' => 25.5, 'volume' => 40000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    foreach (['1M', '3M', '6M', '1Y', '5Y', 'All'] as $range) {
        $response = $this->actingAs($user)->get("/stocks/ARVN/candles?interval=1day&range={$range}");
        $response->assertOk();
        $data = $response->json();
        expect($data)->toHaveKeys(['symbol', 'interval', 'from', 'to', 'candles']);
        expect($data['symbol'])->toBe('ARVN');
    }
});

test('candles json endpoint enforces sensible upper bounds on intraday intervals', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    app()->instance(MarketDataProvider::class, $fake);

    // Request 1min with a huge 5-year lookback range
    $response = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1min&from=2021-01-01&to=2026-10-25');
    $response->assertOk();
    $data = $response->json();

    // From date must be clamped to within 30 days of to date
    $fromDate = \Carbon\Carbon::parse($data['from']);
    $toDate = \Carbon\Carbon::parse($data['to']);
    expect($toDate->diffInDays($fromDate))->toBeLessThanOrEqual(30);
});

test('stock analysis page renders chart container and loads stock-chart script for indicators and chart interactivity', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    $fake->dailyBars['ARVN'] = [
        ['date' => '2026-10-24', 'open' => 26.50, 'high' => 27.50, 'low' => 26.10, 'close' => 27.25, 'volume' => 700000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN');
    $response->assertOk();

    // Verify stock chart script asset is loaded
    $response->assertSee('stock-chart');

    // Verify chart container DOM and Alpine persistence context
    $response->assertSeeHtml('x-ref="chartContainer"');
    $response->assertSeeHtml('data-testid="vela-chart-container"');
    $response->assertSee('duoasset_vela_');
    $response->assertSee('duoasset_chart_prefs_');
});

test('candles json endpoint validates input parameters and returns 422 for malformed requests', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    app()->instance(MarketDataProvider::class, $fake);

    // Invalid interval
    $this->actingAs($user)->get('/stocks/ARVN/candles?interval=unknown_interval')
        ->assertStatus(422);

    // Invalid range
    $this->actingAs($user)->get('/stocks/ARVN/candles?range=10Y')
        ->assertStatus(422);

    // Malformed from date
    $this->actingAs($user)->get('/stocks/ARVN/candles?from=not-a-date')
        ->assertStatus(422);

    // Malformed to date
    $this->actingAs($user)->get('/stocks/ARVN/candles?to=not-a-date')
        ->assertStatus(422);

    // from date after to date
    $this->actingAs($user)->get('/stocks/ARVN/candles?from=2026-10-25&to=2026-10-20')
        ->assertStatus(422);
});

test('candles normalization retains legitimate zero-volume bars and rejects negative volume', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['ARVN'] = [
        ['date' => '2026-10-20', 'open' => 20.0, 'high' => 21.0, 'low' => 19.5, 'close' => 20.5, 'volume' => 0],
        ['date' => '2026-10-21', 'open' => 20.5, 'high' => 21.5, 'low' => 20.0, 'close' => 21.0, 'volume' => -100],
        ['date' => '2026-10-22', 'open' => 21.0, 'high' => 22.0, 'low' => 20.5, 'close' => 21.5, 'volume' => 50000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1day');
    $response->assertOk();
    $data = $response->json();

    expect($data['candles'])->toHaveCount(2);
    expect($data['candles'][0]['volume'])->toEqual(0.0);
    expect($data['candles'][1]['volume'])->toEqual(50000.0);
});

test('candles normalization enforces date bounds and excludes future candles', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->dailyBars['ARVN'] = [
        ['date' => '2026-09-01', 'open' => 18.0, 'high' => 19.0, 'low' => 17.5, 'close' => 18.5, 'volume' => 10000],
        ['date' => '2026-10-15', 'open' => 20.0, 'high' => 21.0, 'low' => 19.5, 'close' => 20.5, 'volume' => 20000],
        ['date' => '2026-10-20', 'open' => 21.0, 'high' => 22.0, 'low' => 20.5, 'close' => 21.5, 'volume' => 30000],
        ['date' => '2026-11-01', 'open' => 22.0, 'high' => 23.0, 'low' => 21.5, 'close' => 22.5, 'volume' => 40000],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1day&from=2026-10-01&to=2026-10-25');
    $response->assertOk();
    $data = $response->json();

    expect($data['candles'])->toHaveCount(2);
    expect($data['candles'][0]['open'])->toEqual(20.0);
    expect($data['candles'][1]['open'])->toEqual(21.0);
});

test('stock analysis page renders dedicated vela-mount child element separated from overlays', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN');
    $response->assertOk();

    // Verify dedicated mount node exists inside chart container
    $response->assertSeeHtml('data-vela-mount');
    $response->assertSeeHtml('x-ref="velaMount"');
    $response->assertSeeHtml('data-testid="vela-chart-container"');
    $response->assertSeeHtml('data-testid="chart-error-state"');
    $response->assertSeeHtml('data-testid="chart-empty-state"');
});

test('setup score panel exposes results for multiple setup types when available', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'vcp_breakout',
        'setup_score' => 88,
        'spike_date' => '2026-10-15',
        'status' => 'active',
        'detected_at' => now()->subDay(),
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'heartbeat_spike',
        'setup_score' => 74,
        'spike_date' => '2026-10-10',
        'status' => 'active',
        'detected_at' => now()->subDays(2),
    ]);

    Livewire::actingAs($user)
        ->test(StockShow::class, ['symbol' => 'ARVN'])
        ->assertSeeHtml('data-testid="multi-setup-types"')
        ->assertSee('vcp_breakout')
        ->assertSee('heartbeat_spike')
        ->assertSee('88/100')
        ->assertSee('74/100')
        ->assertSeeHtml('data-testid="setup-type-toggle-vcp_breakout"')
        ->assertSeeHtml('data-testid="setup-type-toggle-heartbeat_spike"');
});

test('user can interactively toggle active setup type and update setup score panel fields and breakdown', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    // Create 3 active setup types (mirroring production scenario with 3 setup types)
    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'vcp_breakout',
        'setup_score' => 88,
        'raw_setup_score' => 93,
        'heartbeat_score' => 84.0,
        'base_duration_days' => 21,
        'range_compression_pct' => 4.2,
        'spike_date' => '2026-10-25',
        'status' => 'active',
        'detected_at' => '2026-10-25 14:00:00',
        'reason_summary' => 'VCP contraction breakout with high volume expansion',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'heartbeat_spike',
        'setup_score' => 74,
        'raw_setup_score' => 78,
        'heartbeat_score' => 72.0,
        'base_duration_days' => 35,
        'range_compression_pct' => 8.5,
        'spike_date' => '2026-10-20',
        'status' => 'active',
        'detected_at' => '2026-10-20 11:30:00',
        'reason_summary' => 'High volume heartbeat spike following base consolidation',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'range_compression',
        'setup_score' => 62,
        'raw_setup_score' => 65,
        'heartbeat_score' => 60.0,
        'base_duration_days' => 45,
        'range_compression_pct' => 2.1,
        'spike_date' => '2026-10-18',
        'status' => 'active',
        'detected_at' => '2026-10-18 09:45:00',
        'reason_summary' => 'Tight multi-week range compression',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockShow::class, ['symbol' => 'ARVN']);

    // 1. Initial state defaults to the latest alert (vcp_breakout)
    $component
        ->assertSet('selectedSetupType', 'vcp_breakout')
        ->assertSeeHtml('data-testid="overall-setup-score"')
        ->assertSee('88')
        ->assertSee('vcp_breakout')
        ->assertSee('93')
        ->assertSee('84')
        ->assertSee('21 days')
        ->assertSee('4.2%')
        ->assertSee('VCP contraction breakout with high volume expansion')
        ->assertSeeHtml('aria-selected="true"')
        ->assertSeeHtml('data-testid="setup-type-toggle-vcp_breakout"');

    // 2. Toggle to heartbeat_spike
    $component->call('selectSetupType', 'heartbeat_spike')
        ->assertSet('selectedSetupType', 'heartbeat_spike')
        ->assertSeeHtml('data-testid="overall-setup-score"')
        ->assertSee('74')
        ->assertSee('heartbeat_spike')
        ->assertSee('78')
        ->assertSee('72')
        ->assertSee('35 days')
        ->assertSee('8.5%')
        ->assertSee('High volume heartbeat spike following base consolidation');

    expect($component->get('activeSetupAlert')->setup_type)->toBe('heartbeat_spike');
    expect($component->get('activeSetupAlert')->setup_score)->toBe(74);

    // 3. Toggle to range_compression
    $component->call('selectSetupType', 'range_compression')
        ->assertSet('selectedSetupType', 'range_compression')
        ->assertSeeHtml('data-testid="overall-setup-score"')
        ->assertSee('62')
        ->assertSee('range_compression')
        ->assertSee('65')
        ->assertSee('60')
        ->assertSee('45 days')
        ->assertSee('2.1%')
        ->assertSee('Tight multi-week range compression');

    expect($component->get('activeSetupAlert')->setup_type)->toBe('range_compression');
    expect($component->get('activeSetupAlert')->setup_score)->toBe(62);

    // 4. Toggle back to vcp_breakout
    $component->call('selectSetupType', 'vcp_breakout')
        ->assertSet('selectedSetupType', 'vcp_breakout')
        ->assertSee('88')
        ->assertSee('vcp_breakout')
        ->assertSee('93')
        ->assertSee('21 days')
        ->assertSee('4.2%');

    expect($component->get('activeSetupAlert')->setup_type)->toBe('vcp_breakout');
    expect($component->get('activeSetupAlert')->setup_score)->toBe(88);
});

test('selecting setup type picks the latest alert when multiple historical alerts exist for the same type', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    // Two alerts for heartbeat_spike (older and newer)
    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'heartbeat_spike',
        'setup_score' => 50,
        'spike_date' => '2026-09-01',
        'status' => 'active',
        'detected_at' => '2026-09-01 10:00:00',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'heartbeat_spike',
        'setup_score' => 82,
        'spike_date' => '2026-10-20',
        'status' => 'active',
        'detected_at' => '2026-10-20 10:00:00',
    ]);

    // One alert for vcp_breakout (latest overall)
    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'vcp_breakout',
        'setup_score' => 90,
        'spike_date' => '2026-10-22',
        'status' => 'active',
        'detected_at' => '2026-10-22 10:00:00',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockShow::class, ['symbol' => 'ARVN']);

    // Defaults to vcp_breakout (90)
    $component->assertSet('selectedSetupType', 'vcp_breakout')
        ->assertSee('90');

    // Switching to heartbeat_spike should pick the newer 82 score, not the 50 score
    $component->call('selectSetupType', 'heartbeat_spike')
        ->assertSet('selectedSetupType', 'heartbeat_spike')
        ->assertSee('82')
        ->assertDontSee('50/100');

    expect($component->get('activeSetupAlert')->setup_score)->toBe(82);
    expect($component->get('activeSetupAlert')->spike_date->toDateString())->toBe('2026-10-20');
});

test('selecting invalid setup type safely falls back to sensible active alert', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'vcp_breakout',
        'setup_score' => 88,
        'spike_date' => '2026-10-15',
        'status' => 'active',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockShow::class, ['symbol' => 'ARVN']);

    $component->call('selectSetupType', 'non_existent_setup')
        ->assertSet('selectedSetupType', 'vcp_breakout')
        ->assertSee('88');

    expect($component->get('activeSetupAlert')->setup_type)->toBe('vcp_breakout');
});

test('property update hook and toggle alias update selected setup type', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    app()->instance(MarketDataProvider::class, $fake);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'vcp_breakout',
        'setup_score' => 88,
        'spike_date' => '2026-10-15',
        'status' => 'active',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'ARVN',
        'setup_type' => 'heartbeat_spike',
        'setup_score' => 74,
        'spike_date' => '2026-10-10',
        'status' => 'active',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockShow::class, ['symbol' => 'ARVN']);

    // Test toggleSetupType alias
    $component->call('toggleSetupType', 'heartbeat_spike')
        ->assertSet('selectedSetupType', 'heartbeat_spike')
        ->assertSee('74');

    // Test set('selectedSetupType', ...) hook
    $component->set('selectedSetupType', 'vcp_breakout')
        ->assertSet('selectedSetupType', 'vcp_breakout')
        ->assertSee('88');
});

test('candles json endpoint distinguishes provider rate limit failure from empty bars and returns 429', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->errors = [
        ['path' => 'historical-price-eod/full', 'status' => 429, 'body' => 'Rate limit exceeded'],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1day');
    $response->assertStatus(429);
    $data = $response->json();

    expect($data)->toHaveKeys(['message', 'provider_status', 'error']);
    expect($data['provider_status'])->toBe(429);
    expect($data['message'])->toContain('rate limit');
});

test('candles json endpoint distinguishes provider error from empty bars and returns 502', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->errors = [
        ['path' => 'historical-price-eod/full', 'status' => 500, 'body' => 'Internal server error'],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1day');
    $response->assertStatus(502);
    $data = $response->json();

    expect($data)->toHaveKeys(['message', 'provider_status', 'error']);
    expect($data['provider_status'])->toBe(500);
    expect($data['message'])->toContain('currently unavailable');
});

test('candles json endpoint returns 200 with empty candles when provider has no bars and no errors', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN/candles?interval=1day');
    $response->assertOk();
    $data = $response->json();

    expect($data['count'])->toBe(0);
    expect($data['candles'])->toBe([]);
});

test('stock analysis page surfaces provider failure message when initial candle load fails', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['ARVN'] = ['symbol' => 'ARVN', 'price' => 28.50];
    $fake->errors = [
        ['path' => 'historical-price-eod/full', 'status' => 429, 'body' => 'Too Many Requests'],
    ];
    app()->instance(MarketDataProvider::class, $fake);

    $response = $this->actingAs($user)->get('/stocks/ARVN');
    $response->assertOk();
    $response->assertSee('Market data rate limit reached');
});
