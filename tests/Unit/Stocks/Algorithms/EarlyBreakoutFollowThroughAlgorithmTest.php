<?php

use App\Services\Stocks\Algorithms\EarlyBreakoutFollowThroughAlgorithm;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Builds a synthetic 255-bar series shaped as:
 *  - bars 0-249 (250 bars): a perfectly flat base (close 50.0, high 50.3,
 *    low 49.7, volume 100,000) so no accidental undercut/follow-through
 *    pair can form anywhere in this zone (0% gain everywhere).
 *  - bar 250: a fresh short-term undercut day — a clear multi-week low
 *    (low 45.0), well below the preceding 10-day low of ~49.7.
 *  - bar 251: a quiet transition day that stays above the undercut low
 *    (low 46.0), so it can never itself qualify as a fresher undercut day.
 *  - bar 252: the follow-through day — closes up ~7.5% vs its own
 *    previous close, on 3x the trailing 50-bar average volume.
 *  - bars 253-254: flat continuation, filling out the tail of the
 *    recent-spike window.
 *
 * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}>
 */
function buildEarlyBreakoutTestBars(): array
{
    $bars = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2024-01-01');

    for ($i = 0; $i < 255; $i++) {
        $date = $baseDate->addDays($i)->toDateString();

        if ($i < 250) {
            // Flat base zone.
            $close = 50.0;
            $high = 50.3;
            $low = 49.7;
            $volume = 100_000;
        } elseif ($i === 250) {
            // Undercut day: a clear fresh short-term low.
            $close = 46.0;
            $high = 46.5;
            $low = 45.0;
            $volume = 100_000;
        } elseif ($i === 251) {
            // Quiet transition day, still above the undercut low.
            $close = 46.5;
            $high = 47.0;
            $low = 46.0;
            $volume = 100_000;
        } elseif ($i === 252) {
            // Follow-through day: strong gain on a volume surge.
            $close = 50.0;
            $high = 50.5;
            $low = 49.7;
            $volume = 300_000;
        } else {
            // Flat continuation.
            $close = 50.0;
            $high = 50.3;
            $low = 49.7;
            $volume = 100_000;
        }

        $bars[] = [
            'date' => $date,
            'open' => $close,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
        ];
    }

    return $bars;
}

/**
 * A 255-bar series with no undercut/follow-through pattern anywhere: a
 * perfectly flat base throughout, so the detector must fall back to the
 * "still forming, no follow-through confirmed yet" path.
 *
 * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}>
 */
function buildEarlyBreakoutFlatBars(): array
{
    $bars = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2024-01-01');

    for ($i = 0; $i < 255; $i++) {
        $date = $baseDate->addDays($i)->toDateString();

        $bars[] = [
            'date' => $date,
            'open' => 50.0,
            'high' => 50.3,
            'low' => 49.7,
            'close' => 50.0,
            'volume' => 100_000,
        ];
    }

    return $bars;
}

function earlyBreakoutTypeConfig(array $overrides = []): array
{
    return array_merge([
        'min_base_days' => 20,
        'max_base_days' => 40,
        'recent_spike_window_days' => 60,
        'undercut_lookback_days' => 10,
        'followthrough_max_days' => 4,
        'followthrough_min_gain_pct' => 1.5,
        'followthrough_volume_multiplier' => 1.25,
    ], $overrides);
}

test('detects the follow-through day as the anchor after a fresh undercut low', function () {
    $bars = buildEarlyBreakoutTestBars();
    $algorithm = new EarlyBreakoutFollowThroughAlgorithm;

    $result = $algorithm->detect($bars, [], ['symbol' => 'FTDY'], earlyBreakoutTypeConfig(), 'early_breakout_followthrough');

    expect($result)->not->toBeNull()
        ->and($result->symbol)->toBe('FTDY')
        ->and($result->setupType)->toBe('early_breakout_followthrough')
        // The follow-through bar (index 252) is 2 bars before the last bar (254).
        ->and($result->spikeAgeBars)->toBe(2)
        ->and($result->spikeVolume)->toBe(300_000)
        ->and($result->spikeRarityPoints)->toBeGreaterThan(0)
        ->and($result->baseDurationDays)->toBe(40)
        ->and($result->reasonSummary)->toContain('Technical:')
        ->and($result->reasonSummary)->toContain('Follow-through day')
        ->and($result->spikeRarityDescription)->toContain('bars after undercut low');
});

test('rejects symbols with fewer than 252 bars of history', function () {
    $bars = array_slice(buildEarlyBreakoutTestBars(), 0, 200);
    $algorithm = new EarlyBreakoutFollowThroughAlgorithm;

    $result = $algorithm->detect($bars, [], ['symbol' => 'FTDY'], earlyBreakoutTypeConfig(), 'early_breakout_followthrough');

    expect($result)->toBeNull()
        ->and($algorithm->lastRejectionReason())->toContain('insufficient history');
});

test('respects per-setup-type market cap eligibility', function () {
    $bars = buildEarlyBreakoutTestBars();
    $algorithm = new EarlyBreakoutFollowThroughAlgorithm;

    $result = $algorithm->detect(
        $bars,
        [],
        ['symbol' => 'FTDY', 'market_cap' => 10_000_000],
        earlyBreakoutTypeConfig(['min_market_cap' => 50_000_000, 'max_market_cap' => 1_000_000_000_000]),
        'early_breakout_followthrough',
    );

    expect($result)->toBeNull()
        ->and($algorithm->lastRejectionReason())->toContain('market cap below setup minimum');
});

test('accepts symbols within the configured market cap range', function () {
    $bars = buildEarlyBreakoutTestBars();
    $algorithm = new EarlyBreakoutFollowThroughAlgorithm;

    $result = $algorithm->detect(
        $bars,
        [],
        ['symbol' => 'FTDY', 'market_cap' => 500_000_000],
        earlyBreakoutTypeConfig(['min_market_cap' => 50_000_000, 'max_market_cap' => 1_000_000_000_000]),
        'early_breakout_followthrough',
    );

    expect($result)->not->toBeNull()
        ->and($result->marketCap)->toBe(500_000_000);
});

test('key and label identify the algorithm for the registry and config UI', function () {
    $algorithm = new EarlyBreakoutFollowThroughAlgorithm;

    expect($algorithm->key())->toBe('early_breakout_followthrough')
        ->and($algorithm->label())->toBe('Early breakout follow-through');
});

test('falls back to the still-forming state when no undercut/follow-through pattern is present', function () {
    $bars = buildEarlyBreakoutFlatBars();
    $algorithm = new EarlyBreakoutFollowThroughAlgorithm;

    $result = $algorithm->detect($bars, [], ['symbol' => 'FLAT'], earlyBreakoutTypeConfig(), 'early_breakout_followthrough');

    expect($result)->not->toBeNull()
        ->and($result->spikeRarityPoints)->toBe(0)
        ->and($result->spikeRarityDescription)->toBe('No follow-through confirmed yet')
        ->and($result->reasonSummary)->toContain('No follow-through confirmed yet');
});
