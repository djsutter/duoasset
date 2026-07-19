<?php

use App\Enums\SectorFlowDirection;
use App\Services\MoneyFlow\SectorFlowDirectionClassifier;

uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('market_data.moneyflow.direction', [
        'strong_strength' => 60,
        'weak_strength' => 40,
        'velocity_band' => 0.5,
        'acceleration_band' => 0.5,
    ]);
});

it('is stable when there is no prior snapshot (velocity null)', function () {
    expect((new SectorFlowDirectionClassifier)->classify(80.0, null, null))
        ->toBe(SectorFlowDirection::Stable);
});

it('is accelerating when rising, speeding up and not a deep laggard', function () {
    expect((new SectorFlowDirectionClassifier)->classify(70.0, 2.0, 1.5))
        ->toBe(SectorFlowDirection::Accelerating);
});

it('is only improving when rising but decelerating', function () {
    expect((new SectorFlowDirectionClassifier)->classify(70.0, 2.0, -1.0))
        ->toBe(SectorFlowDirection::Improving);
});

it('is improving (not accelerating) when a deep laggard bounces', function () {
    // Rising fast and speeding up, but strength below the weak floor.
    expect((new SectorFlowDirectionClassifier)->classify(20.0, 2.0, 2.0))
        ->toBe(SectorFlowDirection::Improving);
});

it('is weakening when falling and speeding down', function () {
    expect((new SectorFlowDirectionClassifier)->classify(50.0, -2.0, -1.5))
        ->toBe(SectorFlowDirection::Weakening);
});

it('is cooling when falling but decelerating', function () {
    expect((new SectorFlowDirectionClassifier)->classify(50.0, -2.0, 1.0))
        ->toBe(SectorFlowDirection::Cooling);
});

it('is stable inside the velocity band', function () {
    expect((new SectorFlowDirectionClassifier)->classify(50.0, 0.1, 0.1))
        ->toBe(SectorFlowDirection::Stable);
});
