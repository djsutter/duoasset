<?php

use App\Livewire\Watchlists\StockBuySetups;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Services\Stocks\BuySetupConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('it filters by ignition bonus only when ignitionOnly is true', function () {
    $user = User::factory()->create();

    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['bonus_points'] = 8;
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['min_base_days'] = 90;
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['min_volume_dry_up_pct'] = 30.0;
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['price_led_min_relative_volume'] = 2.2;
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['price_led_min_price_change_pct'] = 12.0;
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['volume_led_min_relative_volume'] = 3.0;
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['volume_led_min_price_change_pct'] = 8.0;
    $configService->saveConfig($config);

    // Alert 1: qualifies (150d base, 40% dry-up, 3.5x RVOL, 15% price gain)
    StockBuySetupAlert::create([
        'symbol' => 'QUAL',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 85,
        'base_duration_days' => 150,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    // Alert 2: base too short (30d base)
    StockBuySetupAlert::create([
        'symbol' => 'SHORT',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 80,
        'base_duration_days' => 30,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'spike_date' => now(),
        'detected_at' => now()->subMinute(),
        'status' => 'detected',
    ]);

    // Alert 3: dry-up too low (10%)
    StockBuySetupAlert::create([
        'symbol' => 'NODRY',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 75,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.10,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'spike_date' => now(),
        'detected_at' => now()->subMinutes(2),
        'status' => 'detected',
    ]);

    // Alert 4: neither price-led nor volume-led (1.5x RVOL, 5% price)
    StockBuySetupAlert::create([
        'symbol' => 'LOWVOL',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 70,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 1.5,
        'spike_price_change_pct' => 5.0,
        'spike_date' => now(),
        'detected_at' => now()->subMinutes(3),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null);

    // Unfiltered: all 4 alerts present
    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(4);

    // Filter by ignition bonus only
    $component->set('ignitionOnly', true);
    $filteredAlerts = $component->viewData('alerts');
    expect($filteredAlerts)->toHaveCount(1)
        ->and($filteredAlerts->first()->symbol)->toBe('QUAL');

    // Reset filters
    $component->call('clearFilters');
    expect($component->get('ignitionOnly'))->toBeFalse();
    $resetAlerts = $component->viewData('alerts');
    expect($resetAlerts)->toHaveCount(4);
});

test('it excludes alerts when setup type has ignition bonus_points = 0 even if technicals qualify', function () {
    $user = User::factory()->create();

    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['bonus_points'] = 0; // disabled
    $configService->saveConfig($config);

    StockBuySetupAlert::create([
        'symbol' => 'DISABLED',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 85,
        'base_duration_days' => 150,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null)
        ->set('ignitionOnly', true);

    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(0);
});

test('it configures and renders ignition bonus background color and growth synergy border color', function () {
    $user = User::factory()->create();

    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();

    // Configure Ignition Bonus
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['bonus_points'] = 8;
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['color'] = 'yellow';

    // Configure Growth Synergy Bonus
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus']['enabled'] = true;
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus']['color'] = 'lightgreen';
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus']['max_points'] = 10;
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus']['min_sales_yoy'] = 20;

    $configService->saveConfig($config);

    // Alert 1: Only Ignition Bonus qualifies (no revenue / margin metrics)
    StockBuySetupAlert::create([
        'symbol' => 'IGNONLY',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 85,
        'base_duration_days' => 150,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    // Alert 2: Only Growth Synergy Bonus qualifies (base too short for ignition)
    StockBuySetupAlert::create([
        'symbol' => 'GROWTHONLY',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 85,
        'base_duration_days' => 20, // fails ignition
        'volume_dry_up_score' => 0.10,
        'spike_relative_volume' => 1.0,
        'spike_price_change_pct' => 1.0,
        'quarterly_revenue_growth_pct' => 35.0,
        'sales_acceleration' => 3000.0,
        'operating_margin_expansion_bps' => 1500.0,
        'fcf_margin_expansion_bps' => 1500.0,
        'spike_date' => now(),
        'detected_at' => now()->subMinute(),
        'status' => 'detected',
    ]);

    // Alert 3: BOTH Ignition and Growth Synergy Bonuses qualify
    StockBuySetupAlert::create([
        'symbol' => 'BOTH',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 95,
        'base_duration_days' => 150,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'quarterly_revenue_growth_pct' => 35.0,
        'sales_acceleration' => 3000.0,
        'operating_margin_expansion_bps' => 1500.0,
        'fcf_margin_expansion_bps' => 1500.0,
        'spike_date' => now(),
        'detected_at' => now()->subMinutes(2),
        'status' => 'detected',
    ]);

    // Alert 4: NEITHER bonus qualifies
    StockBuySetupAlert::create([
        'symbol' => 'NEITHER',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 60,
        'base_duration_days' => 20,
        'volume_dry_up_score' => 0.10,
        'spike_relative_volume' => 1.0,
        'spike_price_change_pct' => 1.0,
        'spike_date' => now(),
        'detected_at' => now()->subMinutes(3),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null);

    $html = $component->html();

    // Check that custom background color is applied for Ignition bonus
    expect($html)->toContain('background-color: yellow;');

    // Check that custom outline/border is applied for Growth Synergy bonus
    expect($html)->toContain('outline: 2px solid lightgreen;');

    // Check that both appear when both bonuses qualify
    expect($html)->toContain('background-color: yellow; outline: 2px solid lightgreen;');
});

test('it allows saving custom colors in the setup config modal', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.ignition_bonus.color', 'yellow')
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.growth_synergy_bonus.color', 'lightgreen')
        ->set('configState.setup_types.heartbeat_consolidation_spike.ignition_bonus.color', '#fde047')
        ->set('configState.setup_types.heartbeat_consolidation_spike.growth_synergy_bonus.color', '#22c55e')
        ->call('saveConfig')
        ->assertSee('Buy setup configuration saved successfully.');

    $configService = app(BuySetupConfigService::class);
    expect($configService->getIgnitionBonusConfig('heartbeat_consolidation_spike')['color'])->toBe('#fde047')
        ->and($configService->getGrowthSynergyBonusConfig('heartbeat_consolidation_spike')['color'])->toBe('#22c55e');
});
