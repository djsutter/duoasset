<?php

use App\Livewire\MoneyFlows\Index;
use App\Models\SectorFlowSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')->create([
        'strength' => 90, 'rank' => 1, 'direction' => 'accelerating', 'captured_at' => now(),
    ]);
    SectorFlowSnapshot::factory()->forSectorDate('energy', '2026-07-17')->create([
        'strength' => 40, 'rank' => 2, 'direction' => 'cooling', 'captured_at' => now(),
    ]);
});

it('renders the latest snapshot per sector without calling FMP', function () {
    Http::fake();

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('Technology')
        ->assertSee('Energy')
        ->assertSee('Accelerating');

    Http::assertNothingSent();
});

it('sorts by an allow-listed column and toggles direction', function () {
    Livewire::test(Index::class)
        ->assertSet('sortBy', 'strength')
        ->assertSet('sortDirection', 'desc')
        ->call('sortByColumn', 'daily_change_pct') // new column defaults to desc
        ->assertSet('sortBy', 'daily_change_pct')
        ->assertSet('sortDirection', 'desc')
        ->call('sortByColumn', 'daily_change_pct') // same column toggles
        ->assertSet('sortDirection', 'asc');
});

it('ignores an unknown sort column', function () {
    Livewire::test(Index::class)
        ->set('sortBy', 'strength')
        ->call('sortByColumn', 'drop table')
        ->assertSet('sortBy', 'strength');
});

it('switches between eod and hourly cadences', function () {
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')->hourly('11')->create([
        'strength' => 55, 'captured_at' => now(), 'direction' => 'improving',
    ]);

    Livewire::test(Index::class)
        ->assertSet('interval', 'eod')
        ->call('setInterval', 'hourly')
        ->assertSet('interval', 'hourly')
        ->call('setInterval', 'nonsense')
        ->assertSet('interval', 'hourly'); // invalid ignored
});

it('serves the dashboard route to an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('money-flows.index'))
        ->assertOk()
        ->assertSee('Money Flows');
});
