<?php

use App\Services\Stocks\Algorithms\BuySetupAlgorithm;
use App\Services\Stocks\Algorithms\BuySetupAlgorithmRegistry;
use App\Services\Stocks\Algorithms\HeartbeatConsolidationSpikeAlgorithm;
use App\Services\Stocks\Algorithms\RangeCompressionBreakoutAlgorithm;

test('registry exposes all four built-in algorithm keys', function () {
    expect(BuySetupAlgorithmRegistry::keys())->toEqual([
        'heartbeat_consolidation_spike',
        'range_compression_breakout',
        'floor_reversal_accumulation',
        'early_breakout_followthrough',
    ]);
});

test('registry resolves a known key to the matching algorithm instance', function () {
    $algorithm = BuySetupAlgorithmRegistry::resolve('range_compression_breakout');

    expect($algorithm)->toBeInstanceOf(BuySetupAlgorithm::class)
        ->and($algorithm)->toBeInstanceOf(RangeCompressionBreakoutAlgorithm::class)
        ->and($algorithm->key())->toBe('range_compression_breakout');
});

test('registry falls back to the default (heartbeat) algorithm for a missing or unknown key', function () {
    expect(BuySetupAlgorithmRegistry::resolve(null))->toBeInstanceOf(HeartbeatConsolidationSpikeAlgorithm::class)
        ->and(BuySetupAlgorithmRegistry::resolve('totally_unknown_key'))->toBeInstanceOf(HeartbeatConsolidationSpikeAlgorithm::class)
        ->and(BuySetupAlgorithmRegistry::DEFAULT_KEY)->toBe('heartbeat_consolidation_spike');
});

test('registry options return a label for every registered algorithm, for the config UI dropdown', function () {
    $options = BuySetupAlgorithmRegistry::options();

    expect($options)->toHaveKeys(BuySetupAlgorithmRegistry::keys())
        ->and($options['heartbeat_consolidation_spike'])->toBe('Heartbeat consolidation + spike')
        ->and($options['range_compression_breakout'])->toBe('Range compression breakout');
});

test('registry has() correctly distinguishes known from unknown keys', function () {
    expect(BuySetupAlgorithmRegistry::has('floor_reversal_accumulation'))->toBeTrue()
        ->and(BuySetupAlgorithmRegistry::has('made_up_algorithm'))->toBeFalse();
});

test('only floor_reversal_accumulation is flagged as a reversal-style algorithm', function () {
    expect(BuySetupAlgorithmRegistry::isReversalStyle('floor_reversal_accumulation'))->toBeTrue()
        ->and(BuySetupAlgorithmRegistry::isReversalStyle('heartbeat_consolidation_spike'))->toBeFalse()
        ->and(BuySetupAlgorithmRegistry::isReversalStyle('range_compression_breakout'))->toBeFalse()
        ->and(BuySetupAlgorithmRegistry::isReversalStyle('early_breakout_followthrough'))->toBeFalse()
        ->and(BuySetupAlgorithmRegistry::isReversalStyle('made_up_algorithm'))->toBeFalse();
});
