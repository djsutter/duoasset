<?php

use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Builds 504 daily bars with a high-volume spike 40 bars ago followed by a
 * tight consolidation, sufficient to pass every non-market-cap eligibility
 * gate in StockBuySetupScanner::evaluate().
 *
 * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}>
 */
function buildEligibleBuySetupBars(): array
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

test('a stock above one setup types max market cap is rejected but still evaluated for another setup type with a higher max', function () {
    $service = app(BuySetupConfigService::class);
    $config = $service->getConfig();

    // Setup A: min $50M / max $1B -> a $2B company must be rejected.
    $config['setup_types']['heartbeat_consolidation_spike']['enabled'] = true;
    $config['setup_types']['heartbeat_consolidation_spike']['min_market_cap'] = 50_000_000;
    $config['setup_types']['heartbeat_consolidation_spike']['max_market_cap'] = 1_000_000_000;

    // Setup B: min $50M / max $10B -> the same $2B company remains eligible.
    $rangeBreakout = $service->createDefaultSetupType('range_breakout_wide', 'Range Breakout Wide');
    $rangeBreakout['enabled'] = true;
    $rangeBreakout['min_market_cap'] = 50_000_000;
    $rangeBreakout['max_market_cap'] = 10_000_000_000;
    $config['setup_types']['range_breakout_wide'] = $rangeBreakout;

    $service->saveConfig($config);

    $bars = buildEligibleBuySetupBars();
    $scanner = app(StockBuySetupScanner::class);

    $context = [
        'symbol' => 'MCAP1',
        'company_name' => 'Market Cap Test Co',
        'exchange' => 'NASDAQ',
        'market_cap' => 2_000_000_000, // $2B
    ];

    $results = $scanner->evaluateAll($bars, $bars, $context);
    $resultTypes = array_map(fn ($r) => $r->setupType, $results);

    expect($resultTypes)->not->toContain('heartbeat_consolidation_spike')
        ->and($resultTypes)->toContain('range_breakout_wide');
});

test('a company below the configured minimum market cap is rejected', function () {
    $service = app(BuySetupConfigService::class);
    $config = $service->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['enabled'] = true;
    $config['setup_types']['heartbeat_consolidation_spike']['min_market_cap'] = 50_000_000;
    $config['setup_types']['heartbeat_consolidation_spike']['max_market_cap'] = 1_000_000_000_000;
    $service->saveConfig($config);

    $bars = buildEligibleBuySetupBars();
    $scanner = app(StockBuySetupScanner::class);

    $result = $scanner->evaluate($bars, $bars, [
        'symbol' => 'SMALLCAP',
        'market_cap' => 25_000_000, // below the $50M minimum
    ], $service->getSetupType('heartbeat_consolidation_spike'), 'heartbeat_consolidation_spike');

    expect($result)->toBeNull()
        ->and($scanner->lastRejectionReason())->toContain('market cap below setup minimum');
});

test('a company exactly at the configured minimum market cap is accepted', function () {
    $service = app(BuySetupConfigService::class);
    $config = $service->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['enabled'] = true;
    $config['setup_types']['heartbeat_consolidation_spike']['min_market_cap'] = 50_000_000;
    $config['setup_types']['heartbeat_consolidation_spike']['max_market_cap'] = 1_000_000_000_000;
    $service->saveConfig($config);

    $bars = buildEligibleBuySetupBars();
    $scanner = app(StockBuySetupScanner::class);

    $result = $scanner->evaluate($bars, $bars, [
        'symbol' => 'EXACTMIN',
        'market_cap' => 50_000_000, // exactly at the minimum (inclusive)
    ], $service->getSetupType('heartbeat_consolidation_spike'), 'heartbeat_consolidation_spike');

    expect($result)->not->toBeNull();
});

test('a company exactly at the default $1T maximum market cap is accepted', function () {
    $service = app(BuySetupConfigService::class);
    $bars = buildEligibleBuySetupBars();
    $scanner = app(StockBuySetupScanner::class);

    $result = $scanner->evaluate($bars, $bars, [
        'symbol' => 'EXACTMAX',
        'market_cap' => 1_000_000_000_000, // exactly at the default $1T maximum (inclusive)
    ], $service->getSetupType('heartbeat_consolidation_spike'), 'heartbeat_consolidation_spike');

    expect($result)->not->toBeNull();
});

test('a company above the default $1T maximum market cap is rejected', function () {
    $service = app(BuySetupConfigService::class);
    $bars = buildEligibleBuySetupBars();
    $scanner = app(StockBuySetupScanner::class);

    $result = $scanner->evaluate($bars, $bars, [
        'symbol' => 'ABOVEMAX',
        'market_cap' => 1_000_000_000_001, // just above the default $1T maximum
    ], $service->getSetupType('heartbeat_consolidation_spike'), 'heartbeat_consolidation_spike');

    expect($result)->toBeNull()
        ->and($scanner->lastRejectionReason())->toContain('market cap above setup maximum');
});

test('existing setup type with no stored market cap config defaults to $50M-$1T eligibility', function () {
    // No saveConfig() call: exercise the fresh-defaults code path directly.
    $service = app(BuySetupConfigService::class);
    $bars = buildEligibleBuySetupBars();
    $scanner = app(StockBuySetupScanner::class);

    $tooSmall = $scanner->evaluate($bars, $bars, [
        'symbol' => 'DEFAULTSMALL',
        'market_cap' => 49_999_999,
    ], $service->getSetupType('heartbeat_consolidation_spike'), 'heartbeat_consolidation_spike');

    $justRight = $scanner->evaluate($bars, $bars, [
        'symbol' => 'DEFAULTOK',
        'market_cap' => 50_000_000,
    ], $service->getSetupType('heartbeat_consolidation_spike'), 'heartbeat_consolidation_spike');

    $tooBig = $scanner->evaluate($bars, $bars, [
        'symbol' => 'DEFAULTBIG',
        'market_cap' => 1_000_000_000_001,
    ], $service->getSetupType('heartbeat_consolidation_spike'), 'heartbeat_consolidation_spike');

    expect($tooSmall)->toBeNull()
        ->and($justRight)->not->toBeNull()
        ->and($tooBig)->toBeNull();
});
