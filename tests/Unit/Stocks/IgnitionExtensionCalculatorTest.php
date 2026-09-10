<?php

use App\Services\Stocks\IgnitionExtensionCalculator;
use Carbon\CarbonImmutable;

test('it returns nulls on empty bars or missing spike date', function () {
    $calculator = new IgnitionExtensionCalculator;

    expect($calculator->calculate([], '2026-08-01', 10.0))->toBe([
        'reference_price' => null,
        'current_gain_pct' => null,
        'peak_gain_pct' => null,
    ]);

    $bars = [
        ['date' => '2026-08-01', 'close' => 10.0],
    ];
    expect($calculator->calculate($bars, '2026-08-05', 10.0))->toBe([
        'reference_price' => null,
        'current_gain_pct' => null,
        'peak_gain_pct' => null,
    ]);
});

test('it accepts CarbonImmutable or string spike date and sorts bars', function () {
    $calculator = new IgnitionExtensionCalculator;

    // Unsorted bars
    $bars = [
        ['date' => '2026-08-03', 'close' => 12.0],
        ['date' => '2026-08-01', 'close' => 10.0],
        ['date' => '2026-08-02', 'close' => 11.0], // Spike day: close = 11.0
        ['date' => '2026-08-04', 'close' => 13.2], // +20% from 11.0
    ];

    $res1 = $calculator->calculate($bars, '2026-08-02', 13.2);
    expect($res1['reference_price'])->toBe(11.0)
        ->and($res1['current_gain_pct'])->toEqualWithDelta(20.0, 0.001)
        ->and($res1['peak_gain_pct'])->toEqualWithDelta(20.0, 0.001);

    $res2 = $calculator->calculate($bars, CarbonImmutable::parse('2026-08-02'), 13.2);
    expect($res2['reference_price'])->toBe(11.0)
        ->and($res2['current_gain_pct'])->toEqualWithDelta(20.0, 0.001)
        ->and($res2['peak_gain_pct'])->toEqualWithDelta(20.0, 0.001);
});

test('it preserves peak gain during a pullback', function () {
    $calculator = new IgnitionExtensionCalculator;

    // Spike close = $2.00, later closed at $3.00 (+50%), current price $2.60 (+30%)
    $bars = [
        ['date' => '2026-08-01', 'close' => 1.80],
        ['date' => '2026-08-02', 'close' => 2.00], // Spike close
        ['date' => '2026-08-03', 'close' => 3.00], // +50%
        ['date' => '2026-08-04', 'close' => 2.70],
    ];

    $res = $calculator->calculate($bars, '2026-08-02', 2.60);

    expect($res['reference_price'])->toBe(2.00)
        ->and($res['peak_gain_pct'])->toEqualWithDelta(50.0, 0.001)
        ->and($res['current_gain_pct'])->toEqualWithDelta(30.0, 0.001);
});

test('it falls back to latest bar close when currentPrice is null', function () {
    $calculator = new IgnitionExtensionCalculator;

    $bars = [
        ['date' => '2026-08-01', 'close' => 10.00],
        ['date' => '2026-08-02', 'close' => 10.00], // Spike
        ['date' => '2026-08-03', 'close' => 12.50], // +25%
    ];

    $res = $calculator->calculate($bars, '2026-08-02', null);

    expect($res['reference_price'])->toBe(10.00)
        ->and($res['current_gain_pct'])->toBe(25.0)
        ->and($res['peak_gain_pct'])->toBe(25.0);
});

test('peak gain is non-negative when prices fall below ignition close', function () {
    $calculator = new IgnitionExtensionCalculator;

    $bars = [
        ['date' => '2026-08-01', 'close' => 10.00],
        ['date' => '2026-08-02', 'close' => 10.00], // Spike close
        ['date' => '2026-08-03', 'close' => 8.00],  // -20%
    ];

    $res = $calculator->calculate($bars, '2026-08-02', 8.00);

    expect($res['reference_price'])->toBe(10.00)
        ->and($res['current_gain_pct'])->toBe(-20.0)
        ->and($res['peak_gain_pct'])->toBe(0.0);
});
