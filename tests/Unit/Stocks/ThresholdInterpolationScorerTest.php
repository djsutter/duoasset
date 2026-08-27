<?php

use App\Services\Stocks\ThresholdInterpolationScorer;

test('it interpolates linearly between default operating margin expansion thresholds', function () {
    $scorer = new ThresholdInterpolationScorer;

    // Defaults: threshold_25=250, threshold_50=500, threshold_75=1000, threshold_100=1500
    expect($scorer->score(-500, 250, 500, 1000, 1500))->toBe(0.0)
        ->and($scorer->score(0, 250, 500, 1000, 1500))->toBe(0.0)
        ->and($scorer->score(125, 250, 500, 1000, 1500))->toBe(12.5)
        ->and($scorer->score(250, 250, 500, 1000, 1500))->toBe(25.0)
        ->and($scorer->score(375, 250, 500, 1000, 1500))->toBe(37.5)
        ->and($scorer->score(500, 250, 500, 1000, 1500))->toBe(50.0)
        ->and($scorer->score(750, 250, 500, 1000, 1500))->toBe(62.5)
        ->and($scorer->score(1000, 250, 500, 1000, 1500))->toBe(75.0)
        ->and($scorer->score(1250, 250, 500, 1000, 1500))->toBe(87.5)
        ->and($scorer->score(1500, 250, 500, 1000, 1500))->toBe(100.0)
        ->and($scorer->score(2500, 250, 500, 1000, 1500))->toBe(100.0);
});

test('it clamps the score to 0..100 and never returns a negative score', function () {
    $scorer = new ThresholdInterpolationScorer;

    expect($scorer->score(-10000, 250, 500, 1000, 1500))->toBe(0.0)
        ->and($scorer->score(100000, 250, 500, 1000, 1500))->toBe(100.0);
});

test('it supports custom, non-default thresholds', function () {
    $scorer = new ThresholdInterpolationScorer;

    // threshold_25=100, threshold_50=200, threshold_75=400, threshold_100=800
    expect($scorer->score(50, 100, 200, 400, 800))->toBe(12.5)
        ->and($scorer->score(100, 100, 200, 400, 800))->toBe(25.0)
        ->and($scorer->score(300, 100, 200, 400, 800))->toBe(62.5)
        ->and($scorer->score(800, 100, 200, 400, 800))->toBe(100.0);
});

test('it returns 0 when thresholds are not strictly increasing', function () {
    $scorer = new ThresholdInterpolationScorer;

    expect($scorer->score(300, 500, 500, 1000, 1500))->toBe(0.0)
        ->and($scorer->score(300, 500, 250, 1000, 1500))->toBe(0.0);
});
