<?php

use App\Services\Stocks\StockBuySetupScorer;

it('preserves separation between exceptional sales acceleration values', function () {
    config()->set('market_data.buy_setup_scanner.acceleration_scales.sales_acceleration', 3000);

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
