<?php

use App\Services\MoneyFlow\SectorEtfMetricsCalculator;
use App\Services\MoneyFlow\SectorFlowScorer;

uses(Tests\TestCase::class);

/**
 * @return array<int, array<string, mixed>>
 */
function dailyBars(array $closes, int $volume = 1_000_000): array
{
    $rows = [];
    foreach (array_values($closes) as $i => $close) {
        $rows[] = [
            'date' => sprintf('2026-06-%02d', ($i % 28) + 1),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => (float) $close,
            'volume' => $volume,
        ];
    }

    return $rows;
}

function makeCalculator(): SectorEtfMetricsCalculator
{
    return new SectorEtfMetricsCalculator(new SectorFlowScorer);
}

it('computes valid per-timeframe metrics from sufficient bars', function () {
    $closes = range(100, 139); // 40 ascending closes
    $etf = dailyBars($closes);
    $benchmark = dailyBars(array_fill(0, 40, 100.0)); // flat benchmark

    $metrics = makeCalculator()->calculate('XLK', 'spdr', 1.0, $etf, [], $benchmark, []);

    expect($metrics->valid)->toBeTrue();
    expect($metrics->error)->toBeNull();
    expect($metrics->currentPrice)->toBe(139.0);

    $daily = $metrics->period('daily');
    expect($daily->hasData)->toBeTrue();
    expect(round((float) $daily->changePct, 2))->toBe(0.72); // (139/138 - 1) * 100
    expect($daily->relativeStrength)->toBeGreaterThan(0.0);   // beats a flat benchmark
    expect($daily->outperforms)->toBeTrue();
    expect($daily->score)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(100.0);

    // No intraday bars supplied -> hourly timeframe simply has no data.
    expect($metrics->period('hourly')->hasData)->toBeFalse();
});

it('marks an ETF invalid when it has too few daily bars', function () {
    $metrics = makeCalculator()->calculate('XLK', 'spdr', 1.0, dailyBars([100.0]), [], [], []);

    expect($metrics->valid)->toBeFalse();
    expect($metrics->error)->toContain('insufficient');
});

it('still produces change metrics when the benchmark is missing', function () {
    $etf = dailyBars(range(100, 139));

    $metrics = makeCalculator()->calculate('XLK', 'spdr', 1.0, $etf, [], [], []);

    $daily = $metrics->period('daily');
    expect($daily->hasData)->toBeTrue();
    expect($daily->relativeStrength)->toBeNull(); // no benchmark to compare against
    expect($daily->outperforms)->toBeFalse();
    expect($daily->score)->not->toBeNull();
});
