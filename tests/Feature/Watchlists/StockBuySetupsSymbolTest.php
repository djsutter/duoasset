<?php

use App\Livewire\Watchlists\StockBuySetups;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it can filter by symbol (starts with)', function () {
    $user = User::factory()->create();

    StockBuySetupAlert::create([
        'symbol' => 'AAPL',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 80,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'AMZN',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 85,
        'spike_date' => now(),
        'detected_at' => now()->subMinute(),
        'status' => 'detected',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'MSFT',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 90,
        'spike_date' => now(),
        'detected_at' => now()->subMinutes(2),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null);

    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(3);

    // Filter by "A" -> AAPL, AMZN
    $component->set('symbol', 'A');
    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(2);
    expect($alerts->pluck('symbol'))->toContain('AAPL', 'AMZN');

    // Filter by "AA" -> AAPL
    $component->set('symbol', 'AA');
    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->symbol)->toBe('AAPL');

    // Filter by "Z" -> None
    $component->set('symbol', 'Z');
    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(0);
});

test('it can sort by symbol', function () {
    $user = User::factory()->create();

    StockBuySetupAlert::create([
        'symbol' => 'MSFT',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 80,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'AAPL',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 85,
        'spike_date' => now(),
        'detected_at' => now()->subMinute(),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null);

    // Sort by symbol (desc) -> MSFT, AAPL
    $component->call('sortByColumn', 'symbol');
    $alerts = $component->viewData('alerts');
    expect($alerts->first()->symbol)->toBe('MSFT');

    // Toggle to asc -> AAPL, MSFT
    $component->call('sortByColumn', 'symbol');
    $alerts = $component->viewData('alerts');
    expect($alerts->first()->symbol)->toBe('AAPL');
});
