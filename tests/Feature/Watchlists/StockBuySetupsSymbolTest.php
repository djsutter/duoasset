<?php

use App\Livewire\Watchlists\StockBuySetups;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

test('it can filter by company (contains string)', function () {
    $user = User::factory()->create();

    StockBuySetupAlert::create([
        'symbol' => 'AAPL',
        'company_name' => 'Apple Inc.',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 80,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'MSFT',
        'company_name' => 'Microsoft Corporation',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 85,
        'spike_date' => now(),
        'detected_at' => now()->subMinute(),
        'status' => 'detected',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'GOOGL',
        'company_name' => 'Alphabet Inc.',
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

    // Filter by "Inc" (contains) -> AAPL, GOOGL
    $component->set('company', 'Inc');
    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(2);
    expect($alerts->pluck('symbol'))->toContain('AAPL', 'GOOGL');

    // Filter by "soft" -> MSFT
    $component->set('company', 'soft');
    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->symbol)->toBe('MSFT');

    // Filter by "XYZ" -> None
    $component->set('company', 'XYZ');
    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(0);
});

test('it can sort by company', function () {
    $user = User::factory()->create();

    StockBuySetupAlert::create([
        'symbol' => 'AAPL',
        'company_name' => 'Apple Inc.',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 80,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'MSFT',
        'company_name' => 'Microsoft Corporation',
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

    // Sort by company_name (desc) -> Microsoft, Apple
    $component->call('sortByColumn', 'company_name');
    $alerts = $component->viewData('alerts');
    expect($alerts->first()->symbol)->toBe('MSFT');

    // Toggle to asc -> Apple, Microsoft
    $component->call('sortByColumn', 'company_name');
    $alerts = $component->viewData('alerts');
    expect($alerts->first()->symbol)->toBe('AAPL');
});

test('it can sort by spike date', function () {
    $user = User::factory()->create();

    StockBuySetupAlert::create([
        'symbol' => 'AAPL',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 80,
        'spike_date' => now()->subDays(10),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    StockBuySetupAlert::create([
        'symbol' => 'MSFT',
        'source' => 'test',
        'setup_type' => 'vol_spike',
        'setup_score' => 85,
        'spike_date' => now()->subDays(2),
        'detected_at' => now()->subMinute(),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null);

    // Sort by spike_date (desc) -> MSFT (2 days ago), AAPL (10 days ago)
    $component->call('sortByColumn', 'spike_date');
    $alerts = $component->viewData('alerts');
    expect($alerts->first()->symbol)->toBe('MSFT');

    // Toggle to asc -> AAPL (10 days ago), MSFT (2 days ago)
    $component->call('sortByColumn', 'spike_date');
    $alerts = $component->viewData('alerts');
    expect($alerts->first()->symbol)->toBe('AAPL');
});
