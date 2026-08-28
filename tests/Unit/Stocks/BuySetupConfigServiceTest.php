<?php

use App\Models\Setting;
use App\Services\Stocks\BuySetupConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it returns default configuration matching specification when no setting exists', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    expect($config['scanner_enabled'])->toBeTrue()
        ->and($config['min_setup_score'])->toBe(0)
        ->and($config['notify_min_setup_score'])->toBe(50)
        ->and($config['min_heartbeat_score'])->toBe(50)
        ->and($config['min_market_cap'])->toBe(100000000)
        ->and($config['max_market_cap'])->toBe(1000000000000)
        ->and($config['max_symbols'])->toBe(4000)
        ->and($config['exchanges'])->toEqual(['NYSE', 'NASDAQ', 'TSX', 'TSXV', 'AMEX', 'OTC'])
        ->and($config['history_lookback_days'])->toBe(504)
        ->and($config['benchmark_symbols'])->toEqual(['SPY', 'IWM'])
        ->and($config['notification_email'])->toBe('j@7pro.ca')
        ->and($config['setup_types'])->toHaveKeys([
            'heartbeat_consolidation_spike',
            'range_compression_breakout',
            'floor_reversal_accumulation',
            'early_breakout_followthrough',
        ]);

    $heartbeat = $config['setup_types']['heartbeat_consolidation_spike'];
    expect($heartbeat['enabled'])->toBeTrue()
        ->and($heartbeat['label'])->toBe('Heartbeat consolidation + spike')
        ->and($heartbeat['recent_spike_window_days'])->toBe(60)
        ->and($heartbeat['max_spike_age_days'])->toBe(84)
        ->and($heartbeat['min_base_days'])->toBe(45)
        ->and($heartbeat['max_base_days'])->toBe(120)
        ->and($heartbeat['max_range_compression_pct'])->toBe(40.0)
        ->and($heartbeat['max_atr_ratio'])->toBe(0.85)
        ->and($heartbeat['sleepy_volume_large_cap_penalty_pct'])->toBe(40.0)
        ->and($heartbeat['prior_year_revenue_penalties'])->toEqual([['threshold' => 100000, 'penalty_pct' => 25]])
        ->and($heartbeat['score_weights']['spike_rarity']['weight'])->toBe(25)
        ->and($heartbeat['score_weights']['base_duration']['weight'])->toBe(10)
        ->and($heartbeat['score_weights']['range_compression']['weight'])->toBe(15);
});

test('it persists configuration to the settings table and syncs to runtime config', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    $config['min_market_cap'] = 500000000;
    $config['notification_email'] = 'test@example.com';
    $config['setup_types']['heartbeat_consolidation_spike']['score_weights']['base_duration']['enabled'] = false;

    $service->saveConfig($config);

    $dbSetting = Setting::where('key', BuySetupConfigService::SETTING_KEY)->first();
    expect($dbSetting)->not->toBeNull();

    $saved = json_decode($dbSetting->value, true);
    expect($saved['min_market_cap'])->toBe(500000000)
        ->and($saved['notification_email'])->toBe('test@example.com');

    // Fresh instance loads saved settings
    $freshService = new BuySetupConfigService;
    expect($freshService->getMinMarketCap())->toBe(500000000)
        ->and($freshService->getNotificationEmail())->toBe('test@example.com');

    $weights = $freshService->getScoreWeights('heartbeat_consolidation_spike');
    expect($weights['base_duration'])->toBe(0) // Disabled component has 0 effective weight
        ->and($weights['spike_rarity'])->toBe(25);
});

test('it allows adding custom setup types and resetting to defaults', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    $newType = $service->createDefaultSetupType('breakout_momentum', 'Breakout Momentum');
    $config['setup_types']['breakout_momentum'] = $newType;

    $service->saveConfig($config);

    $savedConfig = (new BuySetupConfigService)->getConfig();
    expect($savedConfig['setup_types'])->toHaveKey('breakout_momentum')
        ->and($savedConfig['setup_types']['breakout_momentum']['label'])->toBe('Breakout Momentum');

    // Reset to defaults
    $service->resetToDefaults();
    $resetConfig = (new BuySetupConfigService)->getConfig();
    expect($resetConfig['setup_types'])->not->toHaveKey('breakout_momentum')
        ->and($resetConfig['setup_types'])->toHaveKey('heartbeat_consolidation_spike');
});

test('it saves and retrieves configurable prior year revenue penalties per setup type', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    // Configure 2 custom tiers for heartbeat_consolidation_spike
    $config['setup_types']['heartbeat_consolidation_spike']['prior_year_revenue_penalties'] = [
        ['threshold' => 100000, 'penalty_pct' => 25],
        ['threshold' => 500000, 'penalty_pct' => 12],
    ];

    // Configure 0 levels (empty array) for range_compression_breakout
    $config['setup_types']['range_compression_breakout']['prior_year_revenue_penalties'] = [];

    $service->saveConfig($config);

    $freshService = new BuySetupConfigService;
    $heartbeatPenalties = $freshService->getPriorYearRevenuePenalties('heartbeat_consolidation_spike');
    expect($heartbeatPenalties)->toHaveCount(2)
        ->and($heartbeatPenalties[0])->toEqual(['threshold' => 100000, 'penalty_pct' => 25])
        ->and($heartbeatPenalties[1])->toEqual(['threshold' => 500000, 'penalty_pct' => 12]);

    $rangePenalties = $freshService->getPriorYearRevenuePenalties('range_compression_breakout');
    expect($rangePenalties)->toBeEmpty();
});

test('it caps prior year revenue penalties at 10 sets and sanitizes invalid values', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    // Supply 12 tiers
    $twelveTiers = [];
    for ($i = 1; $i <= 12; $i++) {
        $twelveTiers[] = ['threshold' => $i * 100000, 'penalty_pct' => min(100, $i * 5)];
    }
    $config['setup_types']['heartbeat_consolidation_spike']['prior_year_revenue_penalties'] = $twelveTiers;

    $service->saveConfig($config);

    $savedPenalties = (new BuySetupConfigService)->getPriorYearRevenuePenalties('heartbeat_consolidation_spike');
    expect($savedPenalties)->toHaveCount(10)
        ->and($savedPenalties[9]['threshold'])->toBe(1000000.0);
});

test('every default setup type defaults to a $50M-$1T market cap range', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    foreach ($config['setup_types'] as $key => $type) {
        expect($type['min_market_cap'])->toBe(50000000)
            ->and($type['max_market_cap'])->toBe(1000000000000);

        $range = $service->getSetupMarketCapRange($key);
        expect($range)->toEqual(['min' => 50000000, 'max' => 1000000000000]);
    }
});

test('a newly created dynamic setup type automatically defaults to a $50M-$1T market cap range', function () {
    $service = new BuySetupConfigService;
    $newType = $service->createDefaultSetupType('breakout_momentum', 'Breakout Momentum');

    expect($newType['min_market_cap'])->toBe(50000000)
        ->and($newType['max_market_cap'])->toBe(1000000000000);

    $config = $service->getConfig();
    $config['setup_types']['breakout_momentum'] = $newType;
    $service->saveConfig($config);

    $range = (new BuySetupConfigService)->getSetupMarketCapRange('breakout_momentum');
    expect($range)->toEqual(['min' => 50000000, 'max' => 1000000000000]);
});

test('a legacy setup type missing market cap settings defaults to $50M-$1T', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    // Simulate a legacy saved config predating this feature: the setup
    // type array has no min_market_cap / max_market_cap keys at all.
    unset($config['setup_types']['heartbeat_consolidation_spike']['min_market_cap']);
    unset($config['setup_types']['heartbeat_consolidation_spike']['max_market_cap']);
    $service->saveConfig($config);

    $freshService = new BuySetupConfigService;
    $range = $freshService->getSetupMarketCapRange('heartbeat_consolidation_spike');

    expect($range)->toEqual(['min' => 50000000, 'max' => 1000000000000]);
});

test('changing one setup types market cap range does not affect another setup type', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    $config['setup_types']['heartbeat_consolidation_spike']['min_market_cap'] = 50000000;
    $config['setup_types']['heartbeat_consolidation_spike']['max_market_cap'] = 1000000000; // $1B

    $config['setup_types']['range_compression_breakout']['min_market_cap'] = 100000000;
    $config['setup_types']['range_compression_breakout']['max_market_cap'] = 100000000000; // $100B

    $service->saveConfig($config);

    $freshService = new BuySetupConfigService;
    expect($freshService->getSetupMarketCapRange('heartbeat_consolidation_spike'))
        ->toEqual(['min' => 50000000, 'max' => 1000000000])
        ->and($freshService->getSetupMarketCapRange('range_compression_breakout'))
        ->toEqual(['min' => 100000000, 'max' => 100000000000]);
});

test('it rejects a setup types market cap range when min exceeds max and falls back to defaults', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    $config['setup_types']['heartbeat_consolidation_spike']['min_market_cap'] = 2000000000;
    $config['setup_types']['heartbeat_consolidation_spike']['max_market_cap'] = 1000000000; // invalid: min > max

    $service->saveConfig($config);

    $range = (new BuySetupConfigService)->getSetupMarketCapRange('heartbeat_consolidation_spike');
    expect($range)->toEqual(['min' => 50000000, 'max' => 1000000000000]);
});

test('every default setup type has the growth synergy bonus disabled by default', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    foreach ($config['setup_types'] as $key => $type) {
        $bonus = $service->getGrowthSynergyBonusConfig($key);

        expect($bonus['enabled'])->toBeFalse()
            ->and($bonus['max_points'])->toBe(10)
            ->and($bonus['min_sales_yoy'])->toBe(20.0)
            ->and($bonus['medium_threshold'])->toBe(50.0)
            ->and($bonus['strong_threshold'])->toBe(75.0)
            ->and($bonus['exceptional_threshold'])->toBe(90.0);
    }
});

test('a newly created dynamic setup type automatically receives the growth synergy bonus defaults', function () {
    $service = new BuySetupConfigService;
    $newType = $service->createDefaultSetupType('breakout_momentum', 'Breakout Momentum');

    expect($newType['growth_synergy_bonus']['enabled'])->toBeFalse()
        ->and($newType['growth_synergy_bonus']['max_points'])->toBe(10);

    $config = $service->getConfig();
    $config['setup_types']['breakout_momentum'] = $newType;
    $service->saveConfig($config);

    $bonus = (new BuySetupConfigService)->getGrowthSynergyBonusConfig('breakout_momentum');
    expect($bonus['enabled'])->toBeFalse()
        ->and($bonus['max_points'])->toBe(10);
});

test('it persists a valid growth synergy bonus configuration', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus'] = [
        'enabled' => true,
        'max_points' => 6,
        'min_sales_yoy' => 15,
        'medium_threshold' => 40,
        'strong_threshold' => 70,
        'exceptional_threshold' => 85,
    ];
    $service->saveConfig($config);

    $bonus = (new BuySetupConfigService)->getGrowthSynergyBonusConfig('heartbeat_consolidation_spike');
    expect($bonus['enabled'])->toBeTrue()
        ->and($bonus['max_points'])->toBe(6)
        ->and($bonus['min_sales_yoy'])->toBe(15.0)
        ->and($bonus['medium_threshold'])->toBe(40.0)
        ->and($bonus['strong_threshold'])->toBe(70.0)
        ->and($bonus['exceptional_threshold'])->toBe(85.0);
});

test('it rejects an invalid growth synergy bonus configuration and falls back to defaults', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    // Invalid: medium >= strong
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus'] = [
        'enabled' => true,
        'max_points' => 6,
        'min_sales_yoy' => 15,
        'medium_threshold' => 80,
        'strong_threshold' => 70,
        'exceptional_threshold' => 90,
    ];
    $service->saveConfig($config);

    $bonus = (new BuySetupConfigService)->getGrowthSynergyBonusConfig('heartbeat_consolidation_spike');
    expect($bonus)->toEqual(BuySetupConfigService::DEFAULT_GROWTH_SYNERGY_BONUS);
});

test('every default setup type defaults to fcf margin expansion thresholds matching operating margin expansion', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    foreach ($config['setup_types'] as $key => $type) {
        $thresholds = $service->getFcfMarginExpansionThresholds($key);

        expect($thresholds)->toEqual(BuySetupConfigService::DEFAULT_FCF_MARGIN_EXPANSION_THRESHOLDS);
    }
});

test('every default setup type defaults its algorithm to its own key', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    foreach (['heartbeat_consolidation_spike', 'range_compression_breakout', 'floor_reversal_accumulation', 'early_breakout_followthrough'] as $key) {
        expect($config['setup_types'][$key]['algorithm'])->toBe($key)
            ->and($service->getSetupAlgorithm($key))->toBe($key);
    }
});

test('a newly created dynamic setup type inherits the heartbeat template algorithm', function () {
    $service = new BuySetupConfigService;
    $newType = $service->createDefaultSetupType('breakout_momentum', 'Breakout Momentum');

    expect($newType['algorithm'])->toBe('heartbeat_consolidation_spike');

    $config = $service->getConfig();
    $config['setup_types']['breakout_momentum'] = $newType;
    $service->saveConfig($config);

    expect((new BuySetupConfigService)->getSetupAlgorithm('breakout_momentum'))->toBe('heartbeat_consolidation_spike');
});

test('it persists a valid custom algorithm choice for a setup type', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    $config['setup_types']['heartbeat_consolidation_spike']['algorithm'] = 'range_compression_breakout';
    $service->saveConfig($config);

    expect((new BuySetupConfigService)->getSetupAlgorithm('heartbeat_consolidation_spike'))->toBe('range_compression_breakout');
});

test('an unknown algorithm key falls back to the setup types own key rather than persisting a broken config', function () {
    $service = new BuySetupConfigService;
    $config = $service->getConfig();

    $config['setup_types']['range_compression_breakout']['algorithm'] = 'not_a_real_algorithm';
    $service->saveConfig($config);

    expect((new BuySetupConfigService)->getSetupAlgorithm('range_compression_breakout'))->toBe('range_compression_breakout');
});
