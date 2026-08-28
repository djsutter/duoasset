<?php

use App\Services\Stocks\Algorithms\RangeCompressionBreakoutAlgorithm;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Builds a synthetic 291-bar series shaped as:
 *  - bars 0-219 (220 bars): "wide" swings (~30%+ range per 40-day window),
 *    so any base window fully inside this zone reads as historically loose.
 *  - bars 220-289 (70 bars): a tight consolidation (~0.5% range per
 *    40-day window), so a window fully inside this zone is genuinely rare
 *    (bottom percentile) compared to the wide-zone-dominated population.
 *  - bar 290: a breakout day, closing well above the tight base's high on
 *    ~2.5x the tight base's average volume.
 *
 * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}>
 */
function buildRangeCompressionTestBars(): array
{
    $bars = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2024-01-01');

    for ($i = 0; $i < 291; $i++) {
        $date = $baseDate->addDays($i)->toDateString();

        if ($i < 220) {
            // Wide zone.
            $close = 50 + 5 * sin($i);
            $high = $close + 2;
            $low = $close - 2;
            $volume = 100_000;
        } elseif ($i < 290) {
            // Tight consolidation zone.
            $close = 58 + 0.05 * sin($i);
            $high = $close + 0.1;
            $low = $close - 0.1;
            $volume = 80_000;
        } else {
            // Breakout day.
            $close = 65.0;
            $high = 65.5;
            $low = 63.0;
            $volume = 200_000;
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

function rangeCompressionTypeConfig(array $overrides = []): array
{
    return array_merge([
        'min_base_days' => 20,
        'max_base_days' => 40,
        'squeeze_percentile' => 20.0,
        'breakout_volume_multiplier' => 1.3,
        'recent_spike_window_days' => 60,
    ], $overrides);
}

test('detects a historically-tight base followed by a moderate-volume breakout', function () {
    $bars = buildRangeCompressionTestBars();
    $algorithm = new RangeCompressionBreakoutAlgorithm;

    $result = $algorithm->detect($bars, [], ['symbol' => 'SQZE'], rangeCompressionTypeConfig(), 'range_compression_breakout');

    expect($result)->not->toBeNull()
        ->and($result->symbol)->toBe('SQZE')
        ->and($result->setupType)->toBe('range_compression_breakout')
        // The selected base must be fully inside the tight zone (bars 220-289).
        ->and($result->rangeCompressionPct)->toBeLessThan(2.0)
        ->and($result->baseDurationDays)->toBe(40)
        // The breakout bar (index 290) is the most recent bar, 0 bars old.
        ->and($result->spikeAgeBars)->toBe(0)
        ->and($result->spikeVolume)->toBe(200_000)
        ->and($result->reasonSummary)->toContain('Technical:')
        ->and($result->reasonSummary)->toContain('Range-compression breakout');
});

test('rejects symbols with fewer than 252 bars of history', function () {
    $bars = array_slice(buildRangeCompressionTestBars(), 0, 200);
    $algorithm = new RangeCompressionBreakoutAlgorithm;

    $result = $algorithm->detect($bars, [], ['symbol' => 'SQZE'], rangeCompressionTypeConfig(), 'range_compression_breakout');

    expect($result)->toBeNull()
        ->and($algorithm->lastRejectionReason())->toContain('insufficient history');
});

test('respects per-setup-type market cap eligibility', function () {
    $bars = buildRangeCompressionTestBars();
    $algorithm = new RangeCompressionBreakoutAlgorithm;

    $result = $algorithm->detect(
        $bars,
        [],
        ['symbol' => 'SQZE', 'market_cap' => 10_000_000],
        rangeCompressionTypeConfig(['min_market_cap' => 50_000_000, 'max_market_cap' => 1_000_000_000_000]),
        'range_compression_breakout',
    );

    expect($result)->toBeNull()
        ->and($algorithm->lastRejectionReason())->toContain('market cap below setup minimum');
});

test('key and label identify the algorithm for the registry and config UI', function () {
    $algorithm = new RangeCompressionBreakoutAlgorithm;

    expect($algorithm->key())->toBe('range_compression_breakout')
        ->and($algorithm->label())->toBe('Range compression breakout');
});
