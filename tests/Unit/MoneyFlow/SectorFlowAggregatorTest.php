<?php

use App\Services\MoneyFlow\EtfMetrics;
use App\Services\MoneyFlow\PeriodMetrics;
use App\Services\MoneyFlow\SectorFlowAggregator;
use App\Services\MoneyFlow\SectorFlowScorer;

uses(Tests\TestCase::class);

function dailyEtf(string $symbol, string $issuer, float $weight, float $score, bool $outperforms = true): EtfMetrics
{
    return new EtfMetrics(
        symbol: $symbol,
        issuer: $issuer,
        weight: $weight,
        valid: true,
        error: null,
        currentPrice: 100.0,
        dataQualityScore: 100.0,
        periods: [
            'daily' => new PeriodMetrics(
                hasData: true,
                changePct: 1.0,
                relativeStrength: $outperforms ? 0.5 : -0.5,
                relativeVolume: 1.2,
                relativeDollarVolume: 1.1,
                score: $score,
                outperforms: $outperforms,
            ),
        ],
    );
}

it('combines ETF scores by weight and composites strength', function () {
    $agg = new SectorFlowAggregator(new SectorFlowScorer);

    $result = $agg->aggregate('technology', 'Technology', [
        dailyEtf('XLK', 'spdr', 1.0, 80.0),
        dailyEtf('VGT', 'vanguard', 3.0, 60.0),
    ]);

    // Weighted daily score = (80*1 + 60*3) / 4 = 65.
    expect(round((float) $result->period('daily')->score, 2))->toBe(65.0);
    expect(round((float) $result->strength, 2))->toBe(65.0);
    expect($result->etfCount)->toBe(2);
});

it('computes issuer breadth as the share of ETFs beating the benchmark', function () {
    $agg = new SectorFlowAggregator(new SectorFlowScorer);

    $result = $agg->aggregate('energy', 'Energy', [
        dailyEtf('XLE', 'spdr', 1.0, 70.0, outperforms: true),
        dailyEtf('VDE', 'vanguard', 1.0, 55.0, outperforms: true),
        dailyEtf('IYE', 'ishares', 1.0, 40.0, outperforms: false),
        dailyEtf('RSPG', 'invesco', 1.0, 45.0, outperforms: false),
    ]);

    // 2 of 4 outperform.
    expect($result->period('daily')->issuerBreadth)->toBe(50.0);
});

it('excludes invalid ETFs from the count but records them in constituents', function () {
    $agg = new SectorFlowAggregator(new SectorFlowScorer);

    $result = $agg->aggregate('technology', 'Technology', [
        dailyEtf('XLK', 'spdr', 1.0, 80.0),
        dailyEtf('VGT', 'vanguard', 1.0, 60.0),
        EtfMetrics::invalid('IYW', 'ishares', 1.0, 'insufficient daily bars'),
    ]);

    expect($result->etfCount)->toBe(2);
    expect($result->constituents)->toHaveKey('IYW');
    expect($result->constituents['IYW']['valid'])->toBeFalse();
    expect($result->constituents['IYW']['error'])->toBe('insufficient daily bars');
});

it('scales confidence down when fewer ETFs are valid', function () {
    $agg = new SectorFlowAggregator(new SectorFlowScorer);

    $five = array_map(
        fn ($i) => dailyEtf("E$i", "issuer$i", 1.0, 60.0),
        range(1, 5),
    );
    expect($agg->aggregate('t', 'T', $five)->confidenceScore)->toBe(100.0);

    $three = array_slice($five, 0, 3);
    expect($agg->aggregate('t', 'T', $three)->confidenceScore)->toBe(70.0);

    $two = array_slice($five, 0, 2);
    expect($agg->aggregate('t', 'T', $two)->confidenceScore)->toBe(40.0);
});
