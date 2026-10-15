<?php

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\StockBuySetupScorer;

test('shows real operating margin change with a zero scored quality gate', function () {
    $alert = new StockBuySetupAlert;
    $alert->operating_margin_expansion_bps = 0;
    $alert->prior_ttm_operating_margin = -1.974;
    $alert->current_ttm_operating_margin = -0.493;
    $method = new ReflectionMethod(StockBuySetupScorer::class, 'operatingMarginExpansionValue');
    expect($method->invoke(new StockBuySetupScorer, $alert))
        ->toBe('+14,810 bps (0 scored: operating loss increased)');
});

test('shows real free cash flow margin change with a zero scored quality gate', function () {
    $alert = new StockBuySetupAlert;
    $alert->fcf_margin_expansion_bps = 0;
    $alert->prior_ttm_fcf_margin = -2.0;
    $alert->current_ttm_fcf_margin = -0.75;
    $method = new ReflectionMethod(StockBuySetupScorer::class, 'fcfMarginExpansionValue');
    expect($method->invoke(new StockBuySetupScorer, $alert))
        ->toBe('+12,500 bps (0 scored: cash burn increased)');
});
