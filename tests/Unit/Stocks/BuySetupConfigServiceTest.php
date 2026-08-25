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
