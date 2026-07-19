<?php

use App\Livewire\Watchlists\EarningsSurprises;
use App\Models\EarningsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('it can filter by symbol (starts with)', function () {
    $user = User::factory()->create();

    EarningsEvent::create([
        'symbol' => 'AAPL',
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now(),
        'eps_surprise_percent' => 10,
    ]);

    EarningsEvent::create([
        'symbol' => 'AMZN',
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now()->subMinute(),
        'eps_surprise_percent' => 10,
    ]);

    EarningsEvent::create([
        'symbol' => 'MSFT',
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now()->subMinutes(2),
        'eps_surprise_percent' => 10,
    ]);

    $component = Livewire::actingAs($user)
        ->test(EarningsSurprises::class)
        ->set('minSurprisePercent', null)
        ->set('minMarketCap', null)
        ->set('alertedOnly', false)
        ->set('direction', 'both');

    $events = $component->viewData('events');
    expect($events)->toHaveCount(3);

    // Filter by "A" -> AAPL, AMZN
    $component->set('symbol', 'A');
    $events = $component->viewData('events');
    expect($events)->toHaveCount(2);
    expect($events->pluck('symbol'))->toContain('AAPL', 'AMZN');

    // Filter by "AA" -> AAPL
    $component->set('symbol', 'AA');
    $events = $component->viewData('events');
    expect($events)->toHaveCount(1);
    expect($events->first()->symbol)->toBe('AAPL');

    // Filter by "Z" -> None
    $component->set('symbol', 'Z');
    $events = $component->viewData('events');
    expect($events)->toHaveCount(0);
});

test('it can sort by symbol', function () {
    $user = User::factory()->create();

    EarningsEvent::create([
        'symbol' => 'MSFT',
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now(),
        'eps_surprise_percent' => 10,
    ]);

    EarningsEvent::create([
        'symbol' => 'AAPL',
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now()->subMinute(),
        'eps_surprise_percent' => 10,
    ]);

    $component = Livewire::actingAs($user)
        ->test(EarningsSurprises::class)
        ->set('minSurprisePercent', null)
        ->set('minMarketCap', null)
        ->set('alertedOnly', false)
        ->set('direction', 'both');

    // Sort by symbol (default desc for sortBy) -> MSFT, AAPL
    $component->call('sortBy', 'symbol');
    $events = $component->viewData('events');
    expect($events->first()->symbol)->toBe('MSFT');

    // Toggle to asc -> AAPL, MSFT
    $component->call('sortBy', 'symbol');
    $events = $component->viewData('events');
    expect($events->first()->symbol)->toBe('AAPL');
});
