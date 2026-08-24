<?php

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Livewire\Stocks\Index as StocksIndex;
use App\Models\Stock;
use App\Models\User;
use App\Types\FiatMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('it can sort by daily change', function () {
    $user = User::factory()->create();

    Stock::factory()->create([
        'symbol' => 'LOW',
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'daily_change' => FiatMoney::fromDecimal(1.50, Currency::USD->value),
    ]);

    Stock::factory()->create([
        'symbol' => 'HIGH',
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'daily_change' => FiatMoney::fromDecimal(10.00, Currency::USD->value),
    ]);

    $component = Livewire::actingAs($user)
        ->test(StocksIndex::class);

    // Sort by daily_change asc -> LOW, HIGH
    $component->call('sortBy', 'daily_change');
    $stocks = $component->viewData('stocks');
    expect($stocks->first()->symbol)->toBe('LOW');

    // Toggle to desc -> HIGH, LOW
    $component->call('sortBy', 'daily_change');
    $stocks = $component->viewData('stocks');
    expect($stocks->first()->symbol)->toBe('HIGH');
});

test('it can sort by daily change percent', function () {
    $user = User::factory()->create();

    Stock::factory()->create([
        'symbol' => 'LOW_PCT',
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'daily_change_percent' => 10000, // 1.00%
    ]);

    Stock::factory()->create([
        'symbol' => 'HIGH_PCT',
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'daily_change_percent' => 50000, // 5.00%
    ]);

    $component = Livewire::actingAs($user)
        ->test(StocksIndex::class);

    // Sort by daily_change_percent asc -> LOW_PCT, HIGH_PCT
    $component->call('sortBy', 'daily_change_percent');
    $stocks = $component->viewData('stocks');
    expect($stocks->first()->symbol)->toBe('LOW_PCT');

    // Toggle to desc -> HIGH_PCT, LOW_PCT
    $component->call('sortBy', 'daily_change_percent');
    $stocks = $component->viewData('stocks');
    expect($stocks->first()->symbol)->toBe('HIGH_PCT');
});

test('it can sort by last checked at', function () {
    $user = User::factory()->create();

    Stock::factory()->create([
        'symbol' => 'OLD',
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'last_checked_at' => now()->subDays(5),
    ]);

    Stock::factory()->create([
        'symbol' => 'RECENT',
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'last_checked_at' => now()->subHour(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(StocksIndex::class);

    // Sort by last_checked_at asc -> OLD, RECENT
    $component->call('sortBy', 'last_checked_at');
    $stocks = $component->viewData('stocks');
    expect($stocks->first()->symbol)->toBe('OLD');

    // Toggle to desc -> RECENT, OLD
    $component->call('sortBy', 'last_checked_at');
    $stocks = $component->viewData('stocks');
    expect($stocks->first()->symbol)->toBe('RECENT');
});

test('it renders sort buttons for change, change percent, and checked', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StocksIndex::class)
        ->assertSeeHtml('wire:click="sortBy(\'daily_change\')"')
        ->assertSeeHtml('wire:click="sortBy(\'daily_change_percent\')"')
        ->assertSeeHtml('wire:click="sortBy(\'last_checked_at\')"');
});
