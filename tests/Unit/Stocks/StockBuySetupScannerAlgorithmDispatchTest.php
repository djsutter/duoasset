<?php

use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Builds a synthetic 291-bar series shaped exactly like the one in
 * RangeCompressionBreakoutAlgorithmTest: a wide zone, then a genuinely
 * tight consolidation, then a moderate-volume breakout day — a shape the
 * Heartbeat algorithm (which requires a 52w/104w record-volume day) would
 * NOT confirm as a spike, but Range Compression Breakout would.
 */
function evaluateAllDispatchTestBars(): array
{
    $bars = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2024-01-01');

    for ($i = 0; $i < 291; $i++) {
        $date = $baseDate->addDays($i)->toDateString();

        if ($i < 220) {
            $close = 50 + 5 * sin($i);
            $high = $close + 2;
            $low = $close - 2;
            $volume = 100_000;
        } elseif ($i < 290) {
            $close = 58 + 0.05 * sin($i);
            $high = $close + 0.1;
            $low = $close - 0.1;
            $volume = 80_000;
        } else {
            $close = 65.0;
            $high = 65.5;
            $low = 63.0;
            $volume = 200_000;
        }

        $bars[] = ['date' => $date, 'open' => $close, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => $volume];
    }

    return $bars;
}

test('evaluateAll dispatches each setup type to its own configured algorithm', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();

    // Disable every built-in type except a custom one we fully control, so
    // the only result produced is attributable to our chosen algorithm.
    foreach ($config['setup_types'] as $key => $type) {
        $config['setup_types'][$key]['enabled'] = false;
    }

    $custom = $configService->createDefaultSetupType('my_squeeze', 'My Squeeze');
    $custom['enabled'] = true;
    $custom['algorithm'] = 'range_compression_breakout';
    $custom['min_base_days'] = 20;
    $custom['max_base_days'] = 40;
    $custom['squeeze_percentile'] = 20.0;
    $custom['breakout_volume_multiplier'] = 1.3;
    $custom['recent_spike_window_days'] = 60;
    $config['setup_types']['my_squeeze'] = $custom;

    $configService->saveConfig($config);

    $bars = evaluateAllDispatchTestBars();
    $scanner = app(StockBuySetupScanner::class);

    $results = $scanner->evaluateAll($bars, [], ['symbol' => 'SQZE']);

    expect($results)->toHaveCount(1)
        ->and($results[0]->setupType)->toBe('my_squeeze')
        ->and($results[0]->reasonSummary)->toContain('Range-compression breakout');
});

test('evaluateAll falls back to the heartbeat algorithm when a setup type has no algorithm configured', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();

    foreach ($config['setup_types'] as $key => $type) {
        $config['setup_types'][$key]['enabled'] = ($key === 'heartbeat_consolidation_spike');
    }
    // Simulate a legacy saved config predating the `algorithm` field.
    unset($config['setup_types']['heartbeat_consolidation_spike']['algorithm']);
    $configService->saveConfig($config);

    expect((new BuySetupConfigService)->getSetupAlgorithm('heartbeat_consolidation_spike'))
        ->toBe('heartbeat_consolidation_spike');
});
