<?php

use App\Livewire\Watchlists\EarningsSurprises;
use App\Models\EarningsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('it can sort by eps surprise percent', function () {
    $user = User::factory()->create();

    EarningsEvent::create([
        'symbol' => 'LOW',
        'eps_surprise_percent' => 10.0,
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now(),
    ]);

    EarningsEvent::create([
        'symbol' => 'HIGH',
        'eps_surprise_percent' => 50.0,
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now()->subMinute(),
    ]);

    // Default sort is detected_at desc -> [LOW, HIGH]
    $component = Livewire::actingAs($user)
        ->test(EarningsSurprises::class)
        ->set('minSurprisePercent', null)
        ->set('minMarketCap', null)
        ->set('alertedOnly', false)
        ->set('direction', 'both');

    $events = $component->viewData('events');
    expect($events)->toHaveCount(2);
    expect($events->first()->symbol)->toBe('LOW');

    // Sort by eps_surprise_percent (default desc) -> [HIGH, LOW]
    $component->call('sortBy', 'eps_surprise_percent');
    $events = $component->viewData('events');
    expect($events->first()->symbol)->toBe('HIGH');

    // Toggle to asc -> [LOW, HIGH]
    $component->call('sortBy', 'eps_surprise_percent');
    $events = $component->viewData('events');
    expect($events->first()->symbol)->toBe('LOW');
});

test('it can sort by revenue surprise percent', function () {
    $user = User::factory()->create();

    EarningsEvent::create([
        'symbol' => 'LOW_REV',
        'revenue_surprise_percent' => 5.0,
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now(),
    ]);

    EarningsEvent::create([
        'symbol' => 'HIGH_REV',
        'revenue_surprise_percent' => 25.0,
        'source' => 'test',
        'report_date' => now(),
        'detected_at' => now()->subMinute(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(EarningsSurprises::class)
        ->set('minSurprisePercent', null)
        ->set('minMarketCap', null)
        ->set('alertedOnly', false)
        ->set('direction', 'both');

    // Sort by revenue_surprise_percent (default desc) -> [HIGH_REV, LOW_REV]
    $component->call('sortBy', 'revenue_surprise_percent');
    $events = $component->viewData('events');
    expect($events->first()->symbol)->toBe('HIGH_REV');

    // Toggle to asc -> [LOW_REV, HIGH_REV]
    $component->call('sortBy', 'revenue_surprise_percent');
    $events = $component->viewData('events');
    expect($events->first()->symbol)->toBe('LOW_REV');
});
