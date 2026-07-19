<?php

use App\Livewire\MoneyFlows\Widget;
use App\Models\SectorFlowSnapshot;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')->create([
        'strength' => 90, 'rank' => 1, 'direction' => 'accelerating', 'captured_at' => now(),
    ]);
    SectorFlowSnapshot::factory()->forSectorDate('financials', '2026-07-17')->create([
        'strength' => 60, 'rank' => 2, 'direction' => 'improving', 'captured_at' => now(),
    ]);
    SectorFlowSnapshot::factory()->forSectorDate('energy', '2026-07-17')->create([
        'strength' => 25, 'rank' => 3, 'direction' => 'weakening', 'captured_at' => now(),
    ]);
});

it('renders the compact widget without calling FMP', function () {
    Http::fake();

    Livewire::test(Widget::class)
        ->assertOk()
        ->assertSee('Money Flows')
        ->assertSee('Technology');

    Http::assertNothingSent();
});

it('surfaces leading, accelerating and cooling sectors', function () {
    $component = Livewire::test(Widget::class);

    // Leading = top by strength.
    expect($component->viewData('leading')->pluck('sector')->all())
        ->toBe(['technology', 'financials', 'energy']);

    // Accelerating cohort.
    expect($component->viewData('accelerating')->pluck('sector')->all())
        ->toContain('technology');

    // Cooling / weakening cohort.
    expect($component->viewData('cooling')->pluck('sector')->all())
        ->toContain('energy');
});

it('includes the full ranking for the modal', function () {
    $component = Livewire::test(Widget::class);

    expect($component->viewData('ranked')->count())->toBe(3);
    // Highest strength first.
    expect($component->viewData('ranked')->first()->sector)->toBe('technology');
});
