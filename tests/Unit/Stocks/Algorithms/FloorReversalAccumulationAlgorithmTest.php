<?php

use App\Services\Stocks\Algorithms\FloorReversalAccumulationAlgorithm;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Builds a synthetic 300-bar series shaped as:
 *  - bars 0-209 (210 bars): a steady prior decline from ~100 down to ~50,
 *    so the lookback window immediately before the base reads a large
 *    decline % relative to the base's own low.
 *  - bars 210-299 (90 bars): a floor/base zone oscillating between ~47
 *    and ~53 on a repeating 20-bar cycle, producing several low-touches
 *    near the base's own low, each separated by ~20 bars (comfortably
 *    more than the default 5-bar minimum gap). Up-days (rising leg of the
 *    cycle) are assigned materially higher volume than down-days (falling
 *    leg), so the base reads as quiet accumulation (up-day volume clearly
 *    exceeds down-day volume). No bar ever closes above the base's own
 *    high, so no confirmation/breakout day is found and the algorithm
 *    falls back to anchoring on the base's last bar.
 *
 * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}>
 */
function buildFloorReversalTestBars(): array
{
    $bars = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2024-01-01');

    $totalDeclineBars = 210;
    $totalBaseBars = 90;
    $n = $totalDeclineBars + $totalBaseBars;

    $prevClose = null;
    for ($i = 0; $i < $n; $i++) {
        $date = $baseDate->addDays($i)->toDateString();

        if ($i < $totalDeclineBars) {
            // Prior decline: linear fall from 100 to 50.
            $close = 100 - (50 / ($totalDeclineBars - 1)) * $i;
            $volume = 100_000;
        } else {
            // Floor/base zone: 20-bar oscillation between ~47 and ~53.
            $j = $i - $totalDeclineBars;
            $phase = $j % 20;
            if ($phase <= 10) {
                $close = 47 + 0.6 * $phase;
            } else {
                $close = 53 - 0.6 * ($phase - 10);
            }

            if ($prevClose !== null && $close > $prevClose) {
                $volume = 150_000; // up-day
            } elseif ($prevClose !== null && $close < $prevClose) {
                $volume = 50_000; // down-day
            } else {
                $volume = 80_000;
            }
        }

        $bars[] = [
            'date' => $date,
            'open' => $close,
            'high' => $close + 1,
            'low' => $close - 1,
            'close' => $close,
            'volume' => $volume,
        ];

        $prevClose = $close;
    }

    return $bars;
}

function floorReversalTypeConfig(array $overrides = []): array
{
    return array_merge([
        'min_base_days' => 30,
        'max_base_days' => 90,
        'recent_spike_window_days' => 60,
        'decline_lookback_days' => 90,
        'min_decline_pct' => 15.0,
        'floor_touch_tolerance_pct' => 3.0,
        'floor_touch_min_gap_days' => 5,
    ], $overrides);
}

test('detects a prior decline followed by a quiet-accumulation floor', function () {
    $bars = buildFloorReversalTestBars();
    $algorithm = new FloorReversalAccumulationAlgorithm;

    $result = $algorithm->detect($bars, [], ['symbol' => 'FLOR'], floorReversalTypeConfig(), 'floor_reversal_accumulation');

    expect($result)->not->toBeNull()
        ->and($algorithm->lastRejectionReason())->toBeNull()
        ->and($result->symbol)->toBe('FLOR')
        ->and($result->setupType)->toBe('floor_reversal_accumulation')
        ->and($result->baseDurationDays)->toBe(90)
        // No bar ever closes above the base's own high, so no confirmation
        // day is found and the anchor falls back to the base's last bar.
        ->and($result->spikeAgeBars)->toBe(0)
        ->and($result->spikeRarityDescription)->toContain('Floor reversal:')
        ->and($result->spikeRarityDescription)->toContain('touches')
        ->and($result->spikeRarityDescription)->toContain('accumulation ratio')
        // Verified empirically: 5 spaced-out low-touches, a 36.4% prior
        // decline (well above the 15% threshold) and a 3.00x up/down-day
        // volume ratio together max out the 0-7 point scale.
        ->and($result->spikeRarityDescription)->toBe('Floor reversal: 5 touches, decline 36.4%, accumulation ratio 3.00x')
        ->and($result->spikeRarityPoints)->toBe(7)
        // The prior decline (100 -> ~50) is much larger than the base's own
        // range, so the base high/low must sit near the floor zone, not the
        // decline zone.
        ->and($result->baseHigh)->toBeGreaterThan(50.0)
        ->and($result->baseHigh)->toBeLessThan(56.0)
        ->and($result->baseLow)->toBeGreaterThan(44.0)
        ->and($result->baseLow)->toBeLessThan(48.0)
        ->and($result->reasonSummary)->toContain('Technical:')
        ->and($result->reasonSummary)->toContain('Floor reversal:');
});

test('rejects symbols with fewer than 252 bars of history', function () {
    $bars = array_slice(buildFloorReversalTestBars(), 0, 200);
    $algorithm = new FloorReversalAccumulationAlgorithm;

    $result = $algorithm->detect($bars, [], ['symbol' => 'FLOR'], floorReversalTypeConfig(), 'floor_reversal_accumulation');

    expect($result)->toBeNull()
        ->and($algorithm->lastRejectionReason())->toContain('insufficient history');
});

test('respects per-setup-type market cap eligibility', function () {
    $bars = buildFloorReversalTestBars();
    $algorithm = new FloorReversalAccumulationAlgorithm;

    $result = $algorithm->detect(
        $bars,
        [],
        ['symbol' => 'FLOR', 'market_cap' => 10_000_000],
        floorReversalTypeConfig(['min_market_cap' => 50_000_000, 'max_market_cap' => 1_000_000_000_000]),
        'floor_reversal_accumulation',
    );

    expect($result)->toBeNull()
        ->and($algorithm->lastRejectionReason())->toContain('market cap below setup minimum');
});

test('accepts a market cap within the configured eligibility range', function () {
    $bars = buildFloorReversalTestBars();
    $algorithm = new FloorReversalAccumulationAlgorithm;

    $result = $algorithm->detect(
        $bars,
        [],
        ['symbol' => 'FLOR', 'market_cap' => 500_000_000],
        floorReversalTypeConfig(['min_market_cap' => 50_000_000, 'max_market_cap' => 1_000_000_000_000]),
        'floor_reversal_accumulation',
    );

    expect($result)->not->toBeNull()
        ->and($result->marketCap)->toBe(500_000_000);
});

test('key and label identify the algorithm for the registry and config UI', function () {
    $algorithm = new FloorReversalAccumulationAlgorithm;

    expect($algorithm->key())->toBe('floor_reversal_accumulation')
        ->and($algorithm->label())->toBe('Floor reversal / accumulation');
});
