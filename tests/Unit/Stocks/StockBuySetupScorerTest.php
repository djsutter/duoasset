<?php

use App\Services\Stocks\StockBuySetupScorer;

it('preserves separation between exceptional sales acceleration values', function () {
    $scorer = new StockBuySetupScorer;
    $method = new ReflectionMethod($scorer, 'logarithmicBonusPoints');
    $method->setAccessible(true);

    $carePoints = $method->invoke($scorer, 104.3, 34, 3000.0);
    $crnxPoints = $method->invoke($scorer, 2715.0, 34, 3000.0);

    expect($carePoints)->toBe(20)
        ->and($crnxPoints)->toBe(34)
        ->and($crnxPoints)->toBeGreaterThan($carePoints);
});

it('caps logarithmic acceleration scoring at the configured maximum', function () {
    $scorer = new StockBuySetupScorer;
    $method = new ReflectionMethod($scorer, 'logarithmicBonusPoints');
    $method->setAccessible(true);

    expect($method->invoke($scorer, 3000.0, 34, 3000.0))->toBe(34)
        ->and($method->invoke($scorer, 10000.0, 34, 3000.0))->toBe(34)
        ->and($method->invoke($scorer, 0.0, 34, 3000.0))->toBe(0)
        ->and($method->invoke($scorer, null, 34, 3000.0))->toBe(0);
});

it('applies default 25 percent penalty when prior year revenue is under 100_000', function () {
    $scorer = new StockBuySetupScorer;

    // Default config: 1 tier with threshold 100,000 and penalty 25%
    // Under 100,000: 25% penalty -> 34 * (1 - 0.25) = 25.5 -> 26
    expect($scorer->applyPriorYearRevenuePenalty(34, 50_000.0))->toBe(26)
        ->and($scorer->applyPriorYearRevenuePenalty(34, 99_999.0))->toBe(26)
        // At or above 100,000: no penalty -> 34
        ->and($scorer->applyPriorYearRevenuePenalty(34, 100_000.0))->toBe(34)
        ->and($scorer->applyPriorYearRevenuePenalty(34, 500_000.0))->toBe(34)
        // Null revenue or 0 points -> untouched
        ->and($scorer->applyPriorYearRevenuePenalty(34, null))->toBe(34)
        ->and($scorer->applyPriorYearRevenuePenalty(0, 50_000.0))->toBe(0);
});

it('supports two-tier penalty using 100_000 / 25% and 500_000 / 12%', function () {
    $scorer = new StockBuySetupScorer;
    $twoTiers = [
        ['threshold' => 100_000, 'penalty_pct' => 25],
        ['threshold' => 500_000, 'penalty_pct' => 12],
    ];

    // Under 100k -> 25% penalty: 34 * 0.75 = 25.5 -> 26
    expect($scorer->applyPriorYearRevenuePenalty(34, 80_000.0, null, $twoTiers))->toBe(26)
        // Between 100k and 500k -> 12% penalty: 34 * 0.88 = 29.92 -> 30
        ->and($scorer->applyPriorYearRevenuePenalty(34, 250_000.0, null, $twoTiers))->toBe(30)
        ->and($scorer->applyPriorYearRevenuePenalty(34, 499_999.0, null, $twoTiers))->toBe(30)
        // At or above 500k -> 0% penalty (full points): 34
        ->and($scorer->applyPriorYearRevenuePenalty(34, 500_000.0, null, $twoTiers))->toBe(34)
        ->and($scorer->applyPriorYearRevenuePenalty(34, 1_000_000.0, null, $twoTiers))->toBe(34)
        // Null revenue -> no penalty
        ->and($scorer->applyPriorYearRevenuePenalty(34, null, null, $twoTiers))->toBe(34);
});

it('applies 0 percent penalty when 0 levels are configured', function () {
    $scorer = new StockBuySetupScorer;

    // 0 levels configured: points remain untouched regardless of revenue
    expect($scorer->applyPriorYearRevenuePenalty(34, 10_000.0, null, []))->toBe(34)
        ->and($scorer->applyPriorYearRevenuePenalty(34, 0.0, null, []))->toBe(34);
});

it('sorts tiers ascending so lowest threshold is matched first even if provided out of order', function () {
    $scorer = new StockBuySetupScorer;
    $outOfOrderTiers = [
        ['threshold' => 500_000, 'penalty_pct' => 12],
        ['threshold' => 100_000, 'penalty_pct' => 25],
    ];

    // Under 100k still receives 25% penalty
    expect($scorer->applyPriorYearRevenuePenalty(34, 50_000.0, null, $outOfOrderTiers))->toBe(26)
        // 200k receives 12% penalty
        ->and($scorer->applyPriorYearRevenuePenalty(34, 200_000.0, null, $outOfOrderTiers))->toBe(30);
});
