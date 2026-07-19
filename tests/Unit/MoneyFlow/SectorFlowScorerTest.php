<?php

use App\Services\MoneyFlow\SectorFlowScorer;

// Needs the container for config(), but no DB or HTTP — a pure unit test.
uses(Tests\TestCase::class);

it('scores a neutral change at 50', function () {
    $scorer = new SectorFlowScorer;

    expect($scorer->scoreChange(0.0, 1.0))->toBe(50.0);
});

it('scores positive change above 50 and negative below', function () {
    $scorer = new SectorFlowScorer;

    expect($scorer->scoreChange(3.0, 1.0))->toBeGreaterThan(50.0);
    expect($scorer->scoreChange(-3.0, 1.0))->toBeLessThan(50.0);
});

it('returns neutral when the volatility baseline is missing', function () {
    $scorer = new SectorFlowScorer;

    expect($scorer->scoreChange(5.0, null))->toBe(50.0);
    expect($scorer->scoreChange(5.0, 0.0))->toBe(50.0);
});

it('returns null when the raw metric itself is absent', function () {
    $scorer = new SectorFlowScorer;

    expect($scorer->scoreChange(null, 1.0))->toBeNull();
    expect($scorer->scoreRelativeStrength(null, 'daily'))->toBeNull();
    expect($scorer->scoreRelativeVolume(null))->toBeNull();
});

it('scores average relative volume at 50 and heavier volume higher', function () {
    $scorer = new SectorFlowScorer;

    expect($scorer->scoreRelativeVolume(1.0))->toBe(50.0);
    expect($scorer->scoreRelativeVolume(2.5))->toBeGreaterThan(50.0);
    expect($scorer->scoreRelativeVolume(0.3))->toBeLessThan(50.0);
});

it('blends components and renormalizes over whichever are present', function () {
    $scorer = new SectorFlowScorer;

    // Only the change component present -> blend equals it.
    expect($scorer->blendComponents(80.0, null, null))->toBe(80.0);
    // All null -> null.
    expect($scorer->blendComponents(null, null, null))->toBeNull();
    // Equal components -> equal blend regardless of weights.
    expect($scorer->blendComponents(60.0, 60.0, 60.0))->toBe(60.0);
});

it('composites strength across timeframes and ignores missing ones', function () {
    $scorer = new SectorFlowScorer;

    // Only monthly present -> composite equals monthly.
    expect($scorer->compositeStrength([
        'hourly' => null, 'daily' => null, 'weekly' => null, 'monthly' => 75.0,
    ]))->toBe(75.0);

    // Uniform scores -> that score.
    expect($scorer->compositeStrength([
        'hourly' => 40.0, 'daily' => 40.0, 'weekly' => 40.0, 'monthly' => 40.0,
    ]))->toBe(40.0);

    expect($scorer->compositeStrength([]))->toBeNull();
});

it('clamps scores into the 0-100 band', function () {
    $scorer = new SectorFlowScorer;

    expect($scorer->scoreChange(1000.0, 0.01))->toBeLessThanOrEqual(100.0);
    expect($scorer->scoreChange(-1000.0, 0.01))->toBeGreaterThanOrEqual(0.0);
});
