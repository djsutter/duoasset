<?php

use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScanner;
use App\Services\Stocks\StockBuySetupScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}>
 */
function buildReasonSummaryTestBars(): array
{
    $bars = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2025-01-01');
    for ($i = 0; $i < 504; $i++) {
        $date = $baseDate->addDays($i)->toDateString();
        $vol = 100_000;
        $close = 50.0;
        $high = 50.5;
        $low = 49.5;
        $open = 50.0;

        if ($i === 504 - 40) {
            $vol = 1_000_000;
            $high = 60.0;
            $close = 58.0;
        } elseif ($i > 504 - 40) {
            $close = 58.0 + (sin($i) * 0.2);
            $high = $close + 0.3;
            $low = $close - 0.3;
            $open = $close;
            $vol = 50_000;
        }

        $bars[] = [
            'date' => $date,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $vol,
        ];
    }

    return $bars;
}

test('reason summary includes enabled fundamental metrics reused from the score breakdown', function () {
    $bars = buildReasonSummaryTestBars();
    $scanner = app(StockBuySetupScanner::class);
    $service = app(BuySetupConfigService::class);

    // operating_margin_expansion is disabled here (even though it's enabled by
    // default for heartbeat_consolidation_spike) to isolate the earnings/sales
    // acceleration reason-text assertions below from margin-expansion text.
    $config = $service->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['score_weights']['operating_margin_expansion']['enabled'] = false;
    $service->saveConfig($config);

    $context = [
        'symbol' => 'PGEN',
        'earnings_acceleration' => 69.7,
        'sales_acceleration' => 4688.7,
        'prior_year_revenue' => 10_000_000,
        // Disabled above for this setup type, so it must NOT appear.
        'operating_margin_expansion_bps' => 235341.0,
    ];

    $result = $scanner->evaluate(
        $bars,
        $bars,
        $context,
        $service->getSetupType('heartbeat_consolidation_spike'),
        'heartbeat_consolidation_spike',
    );

    expect($result)->not->toBeNull();

    $breakdown = app(StockBuySetupScorer::class)->breakdown($result, 'heartbeat_consolidation_spike', 10_000_000);

    $expectedEarnings = 'Earnings accel +'.$breakdown['earnings_acceleration']['value']
        .' ('.$breakdown['earnings_acceleration']['points'].'/'.$breakdown['earnings_acceleration']['max'].')';
    $expectedSales = 'Sales accel +'.$breakdown['sales_acceleration']['value']
        .' ('.$breakdown['sales_acceleration']['points'].'/'.$breakdown['sales_acceleration']['max'].')';

    // Existing technical reason is retained under its own labelled paragraph.
    expect($result->reasonSummary)->toContain('Technical:')
        ->and($result->reasonSummary)->toContain('base '.$result->baseDurationDays.'d')
        ->and($result->reasonSummary)->toContain('ATR ratio')
        // Fundamental metrics appear under their own labelled paragraph.
        ->and($result->reasonSummary)->toContain('Fundamentals:')
        ->and($result->reasonSummary)->toContain($expectedEarnings)
        ->and($result->reasonSummary)->toContain($expectedSales)
        // Disabled metric must not leak into the reason text.
        ->and($result->reasonSummary)->not->toContain('operating margin expansion')
        ->and($result->reasonSummary)->not->toContain('Operating margin expansion');
});

test('reason summary includes operating margin expansion since it is enabled by default for the setup type', function () {
    $service = app(BuySetupConfigService::class);
    $bars = buildReasonSummaryTestBars();
    $scanner = app(StockBuySetupScanner::class);

    $result = $scanner->evaluate(
        $bars,
        $bars,
        [
            'symbol' => 'PGEN',
            'operating_margin_expansion_bps' => 235341.0,
        ],
        $service->getSetupType('heartbeat_consolidation_spike'),
        'heartbeat_consolidation_spike',
    );

    expect($result)->not->toBeNull()
        ->and($result->reasonSummary)->toContain('Fundamentals:')
        ->and($result->reasonSummary)->toContain('Operating margin expansion +235,341 bps');
});

test('reason summary omits fundamental metrics when their data is unavailable', function () {
    $bars = buildReasonSummaryTestBars();
    $scanner = app(StockBuySetupScanner::class);
    $service = app(BuySetupConfigService::class);

    $result = $scanner->evaluate(
        $bars,
        $bars,
        ['symbol' => 'NODATA'],
        $service->getSetupType('heartbeat_consolidation_spike'),
        'heartbeat_consolidation_spike',
    );

    expect($result)->not->toBeNull()
        ->and($result->reasonSummary)->toContain('Technical:')
        // No fundamental data available, so the Fundamentals paragraph is omitted entirely.
        ->and($result->reasonSummary)->not->toContain('Fundamentals:')
        ->and($result->reasonSummary)->not->toContain('earnings accel')
        ->and($result->reasonSummary)->not->toContain('sales accel')
        ->and($result->reasonSummary)->not->toContain('operating margin expansion');
});
