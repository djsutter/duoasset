<?php

use App\Livewire\Watchlists\StockBuySetups;
use App\Models\User;
use App\Services\Stocks\BuySetupConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('user can open and close the buy setup configuration modal', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->assertSet('configModalOpen', false)
        ->call('openConfigModal')
        ->assertSet('configModalOpen', true)
        ->assertSee('Buy Setup Configuration')
        ->call('closeConfigModal')
        ->assertSet('configModalOpen', false);
});

test('user can update scanner settings and score weights in the modal', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->set('configState.min_market_cap', 250000000)
        ->set('configState.notify_min_setup_score', 65)
        ->set('configState.setup_types.heartbeat_consolidation_spike.score_weights.spike_rarity.weight', 30)
        ->set('configState.setup_types.heartbeat_consolidation_spike.score_weights.base_duration.enabled', false)
        ->call('saveConfig')
        ->assertSee('Buy setup configuration saved successfully.');

    $service = app(BuySetupConfigService::class);
    expect($service->getMinMarketCap())->toBe(250000000)
        ->and($service->getNotifyMinSetupScore())->toBe(65);

    $weights = $service->getScoreWeights('heartbeat_consolidation_spike');
    expect($weights['spike_rarity'])->toBe(30)
        ->and($weights['base_duration'])->toBe(0);
});

test('user can add a new setup type and delete a custom setup type in the modal', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->set('newSetupTypeKey', 'floor_bounce')
        ->set('newSetupTypeLabel', 'Floor Bounce Setup')
        ->call('addSetupType')
        ->assertSet('selectedConfigSetupType', 'floor_bounce')
        ->call('saveConfig');

    $service = app(BuySetupConfigService::class);
    $types = $service->getSetupTypes();
    expect($types)->toHaveKey('floor_bounce')
        ->and($types['floor_bounce']['label'])->toBe('Floor Bounce Setup');

    // Remove custom setup type
    $component->call('removeSetupType', 'floor_bounce')
        ->call('saveConfig');

    $updatedTypes = (new BuySetupConfigService)->getSetupTypes();
    expect($updatedTypes)->not->toHaveKey('floor_bounce');
});

test('default setup type cannot be deleted and reset to defaults works', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->call('removeSetupType', 'heartbeat_consolidation_spike')
        ->assertSee('The default setup type cannot be deleted.')
        ->set('configState.min_market_cap', 999999999)
        ->call('saveConfig');

    expect(app(BuySetupConfigService::class)->getMinMarketCap())->toBe(999999999);

    $component->call('resetConfigToDefaults');
    expect(app(BuySetupConfigService::class)->getMinMarketCap())->toBe(100000000);
});

test('multiple setup types retain distinct configurations when switching and reopening modal', function () {
    $user = User::factory()->create();

    // 1. Open modal, add sales_acceleration setup type, and configure custom values for both types
    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->set('newSetupTypeKey', 'sales_acceleration')
        ->set('newSetupTypeLabel', 'Sales Acceleration')
        ->call('addSetupType')
        ->assertSet('selectedConfigSetupType', 'sales_acceleration')
        // Configure sales_acceleration with distinct values
        ->set('configState.setup_types.sales_acceleration.recent_spike_window_days', 42)
        ->set('configState.setup_types.sales_acceleration.min_base_days', 30)
        ->set('configState.setup_types.sales_acceleration.max_base_days', 90)
        ->set('configState.setup_types.sales_acceleration.score_weights.sales_acceleration.weight', 40)
        ->set('configState.setup_types.sales_acceleration.score_weights.spike_rarity.weight', 5)
        // Configure heartbeat_consolidation_spike with different values
        ->set('configState.setup_types.heartbeat_consolidation_spike.recent_spike_window_days', 65)
        ->set('configState.setup_types.heartbeat_consolidation_spike.min_base_days', 50)
        ->set('configState.setup_types.heartbeat_consolidation_spike.score_weights.sales_acceleration.weight', 5)
        ->set('configState.setup_types.heartbeat_consolidation_spike.score_weights.spike_rarity.weight', 25)
        ->call('saveConfig')
        ->call('closeConfigModal');

    // Verify service persistence
    $service = app(BuySetupConfigService::class);
    $salesType = $service->getSetupType('sales_acceleration');
    $heartbeatType = $service->getSetupType('heartbeat_consolidation_spike');

    expect($salesType['recent_spike_window_days'])->toBe(42)
        ->and($salesType['min_base_days'])->toBe(30)
        ->and($salesType['max_base_days'])->toBe(90)
        ->and($salesType['score_weights']['sales_acceleration']['weight'])->toBe(40)
        ->and($salesType['score_weights']['spike_rarity']['weight'])->toBe(5)
        ->and($heartbeatType['recent_spike_window_days'])->toBe(65)
        ->and($heartbeatType['min_base_days'])->toBe(50)
        ->and($heartbeatType['score_weights']['sales_acceleration']['weight'])->toBe(5)
        ->and($heartbeatType['score_weights']['spike_rarity']['weight'])->toBe(25);

    // 2. Re-open modal and verify values on switching selected setup type
    $component->call('openConfigModal')
        ->call('selectConfigSetupType', 'heartbeat_consolidation_spike')
        ->assertSet('selectedConfigSetupType', 'heartbeat_consolidation_spike')
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.recent_spike_window_days', 65)
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.score_weights.sales_acceleration.weight', 5)
        ->call('selectConfigSetupType', 'sales_acceleration')
        ->assertSet('selectedConfigSetupType', 'sales_acceleration')
        ->assertSet('configState.setup_types.sales_acceleration.recent_spike_window_days', 42)
        ->assertSet('configState.setup_types.sales_acceleration.score_weights.sales_acceleration.weight', 40);

    // 3. Edit heartbeat_consolidation_spike, save, close, reopen, and verify sales_acceleration remains intact
    $component->call('selectConfigSetupType', 'heartbeat_consolidation_spike')
        ->set('configState.setup_types.heartbeat_consolidation_spike.recent_spike_window_days', 75)
        ->call('saveConfig')
        ->call('closeConfigModal')
        ->call('openConfigModal')
        ->call('selectConfigSetupType', 'sales_acceleration')
        ->assertSet('configState.setup_types.sales_acceleration.recent_spike_window_days', 42)
        ->assertSet('configState.setup_types.sales_acceleration.score_weights.sales_acceleration.weight', 40);
});

test('user can add, edit, and remove prior year revenue penalties in the modal', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->assertSet('selectedConfigSetupType', 'heartbeat_consolidation_spike')
        // Default has 1 penalty level: 100k / 25%
        ->assertCount('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties', 1)
        // Add a second penalty level
        ->call('addPriorYearRevenuePenalty', 'heartbeat_consolidation_spike')
        ->assertCount('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties', 2)
        // Configure two tiers: 100k / 25% and 500k / 12%
        ->set('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties.0.threshold', 100000)
        ->set('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties.0.penalty_pct', 25)
        ->set('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties.1.threshold', 500000)
        ->set('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties.1.penalty_pct', 12)
        ->call('saveConfig');

    $service = app(BuySetupConfigService::class);
    $penalties = $service->getPriorYearRevenuePenalties('heartbeat_consolidation_spike');
    expect($penalties)->toHaveCount(2)
        ->and($penalties[0]['threshold'])->toBe(100000.0)
        ->and($penalties[0]['penalty_pct'])->toBe(25.0)
        ->and($penalties[1]['threshold'])->toBe(500000.0)
        ->and($penalties[1]['penalty_pct'])->toBe(12.0);

    // Remove one level
    $component->call('removePriorYearRevenuePenalty', 0, 'heartbeat_consolidation_spike')
        ->assertCount('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties', 1)
        ->call('saveConfig');

    $updatedPenalties = (new BuySetupConfigService)->getPriorYearRevenuePenalties('heartbeat_consolidation_spike');
    expect($updatedPenalties)->toHaveCount(1)
        ->and($updatedPenalties[0]['threshold'])->toBe(500000.0);

    // Remove remaining level to get 0 levels
    $component->call('removePriorYearRevenuePenalty', 0, 'heartbeat_consolidation_spike')
        ->assertCount('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties', 0)
        ->call('saveConfig');

    $emptyPenalties = (new BuySetupConfigService)->getPriorYearRevenuePenalties('heartbeat_consolidation_spike');
    expect($emptyPenalties)->toBeEmpty();
});

test('operating margin expansion defaults to enabled for the enabled setup type and can be re-tuned with custom thresholds', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.score_weights.operating_margin_expansion.enabled', true)
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_25', 250)
        ->set('configState.setup_types.heartbeat_consolidation_spike.score_weights.operating_margin_expansion.enabled', true)
        ->set('configState.setup_types.heartbeat_consolidation_spike.score_weights.operating_margin_expansion.weight', 15)
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_25', 100)
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_50', 200)
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_75', 400)
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_100', 800)
        ->call('saveConfig')
        ->assertSee('Buy setup configuration saved successfully.');

    $service = app(BuySetupConfigService::class);
    $weights = $service->getScoreWeightsMeta('heartbeat_consolidation_spike');
    $thresholds = $service->getOperatingMarginExpansionThresholds('heartbeat_consolidation_spike');

    expect($weights['operating_margin_expansion']['enabled'])->toBeTrue()
        ->and($weights['operating_margin_expansion']['weight'])->toBe(15)
        ->and($thresholds)->toEqual([
            'threshold_25' => 100,
            'threshold_50' => 200,
            'threshold_75' => 400,
            'threshold_100' => 800,
        ]);
});

test('modal rejects saving invalid (non-increasing) operating margin expansion thresholds', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_25', 500)
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_50', 500)
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_75', 1000)
        ->set('configState.setup_types.heartbeat_consolidation_spike.operating_margin_expansion_thresholds.threshold_100', 1500)
        ->call('saveConfig')
        ->assertSee('Operating Margin Expansion thresholds must be positive and strictly increasing');

    // Nothing was persisted — defaults remain intact.
    $thresholds = app(BuySetupConfigService::class)->getOperatingMarginExpansionThresholds('heartbeat_consolidation_spike');
    expect($thresholds['threshold_50'])->toBe(500);
    expect($thresholds['threshold_25'])->toBe(250);
});

test('dynamically created setup types inherit operating margin expansion enabled from the heartbeat template', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->set('newSetupTypeKey', 'new_growth_setup')
        ->set('newSetupTypeLabel', 'New Growth Setup')
        ->call('addSetupType')
        ->call('saveConfig');

    $service = app(BuySetupConfigService::class);
    $weights = $service->getScoreWeightsMeta('new_growth_setup');
    $thresholds = $service->getOperatingMarginExpansionThresholds('new_growth_setup');

    expect($weights['operating_margin_expansion']['enabled'])->toBeTrue()
        ->and($weights['operating_margin_expansion']['weight'])->toBe(10)
        ->and($thresholds)->toEqual([
            'threshold_25' => 250,
            'threshold_50' => 500,
            'threshold_75' => 1000,
            'threshold_100' => 1500,
        ]);
});

test('per-setup type market cap range defaults to $50M-$1T and saves/reloads independently per setup type', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->assertSet('selectedConfigSetupType', 'heartbeat_consolidation_spike')
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.min_market_cap', 50000000)
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.max_market_cap', 1000000000000)
        ->set('configState.setup_types.heartbeat_consolidation_spike.min_market_cap', 50000000)
        ->set('configState.setup_types.heartbeat_consolidation_spike.max_market_cap', 10000000000)
        ->call('selectConfigSetupType', 'range_compression_breakout')
        ->set('configState.setup_types.range_compression_breakout.min_market_cap', 100000000)
        ->set('configState.setup_types.range_compression_breakout.max_market_cap', 100000000000)
        ->call('saveConfig')
        ->assertSee('Buy setup configuration saved successfully.');

    $service = app(BuySetupConfigService::class);
    expect($service->getSetupMarketCapRange('heartbeat_consolidation_spike'))
        ->toEqual(['min' => 50000000, 'max' => 10000000000])
        ->and($service->getSetupMarketCapRange('range_compression_breakout'))
        ->toEqual(['min' => 100000000, 'max' => 100000000000]);

    // Values reload independently for each setup type after reopening.
    $component->call('closeConfigModal')
        ->call('openConfigModal')
        ->call('selectConfigSetupType', 'heartbeat_consolidation_spike')
        ->assertSet('configState.setup_types.heartbeat_consolidation_spike.max_market_cap', 10000000000)
        ->call('selectConfigSetupType', 'range_compression_breakout')
        ->assertSet('configState.setup_types.range_compression_breakout.max_market_cap', 100000000000);
});

test('modal rejects saving a setup types market cap range when minimum exceeds maximum', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->set('configState.setup_types.heartbeat_consolidation_spike.min_market_cap', 2000000000)
        ->set('configState.setup_types.heartbeat_consolidation_spike.max_market_cap', 1000000000)
        ->call('saveConfig')
        ->assertSee('Minimum Market Cap must be >= 0, Maximum Market Cap must be > 0, and Minimum Market Cap must not exceed Maximum Market Cap.');

    // Nothing was persisted — defaults remain intact.
    $range = app(BuySetupConfigService::class)->getSetupMarketCapRange('heartbeat_consolidation_spike');
    expect($range)->toEqual(['min' => 50000000, 'max' => 1000000000000]);
});

test('dynamically created setup types automatically expose the $50M-$1T market cap range', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal')
        ->set('newSetupTypeKey', 'new_mcap_setup')
        ->set('newSetupTypeLabel', 'New Mcap Setup')
        ->call('addSetupType')
        ->call('saveConfig');

    $range = app(BuySetupConfigService::class)->getSetupMarketCapRange('new_mcap_setup');
    expect($range)->toEqual(['min' => 50000000, 'max' => 1000000000000]);
});

test('user cannot add more than 10 prior year revenue penalty levels', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->call('openConfigModal');

    // Add up to 10
    for ($i = 0; $i < 9; $i++) {
        $component->call('addPriorYearRevenuePenalty', 'heartbeat_consolidation_spike');
    }
    $component->assertCount('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties', 10);

    // Attempting to add an 11th should be rejected
    $component->call('addPriorYearRevenuePenalty', 'heartbeat_consolidation_spike')
        ->assertCount('configState.setup_types.heartbeat_consolidation_spike.prior_year_revenue_penalties', 10)
        ->assertSee('A maximum of 10 prior-year revenue penalty levels is allowed.');
});
