<?php

namespace App\Services\Stocks\Algorithms;

/**
 * Maps an algorithm key (stored in a setup type's `algorithm` config value)
 * to the BuySetupAlgorithm implementation that runs it. Adding a new
 * algorithm only requires a new class implementing BuySetupAlgorithm and
 * one line here — StockBuySetupScanner::evaluateAll() and the config UI's
 * algorithm dropdown both read from this registry.
 */
class BuySetupAlgorithmRegistry
{
    /**
     * @var array<string, class-string<BuySetupAlgorithm>>
     */
    private const ALGORITHMS = [
        'heartbeat_consolidation_spike' => HeartbeatConsolidationSpikeAlgorithm::class,
        'range_compression_breakout' => RangeCompressionBreakoutAlgorithm::class,
        'floor_reversal_accumulation' => FloorReversalAccumulationAlgorithm::class,
        'early_breakout_followthrough' => EarlyBreakoutFollowThroughAlgorithm::class,
    ];

    /**
     * The algorithm key used when a setup type has no (or an unknown)
     * `algorithm` configured — the original, always-available detector.
     */
    public const DEFAULT_KEY = 'heartbeat_consolidation_spike';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALGORITHMS);
    }

    public static function has(string $key): bool
    {
        return isset(self::ALGORITHMS[$key]);
    }

    /**
     * Key => label, for the config UI's algorithm dropdown.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::ALGORITHMS as $key => $class) {
            $options[$key] = app($class)->label();
        }

        return $options;
    }

    /**
     * Resolve an algorithm instance for the given key, falling back to the
     * default (Heartbeat Consolidation + Spike) when the key is missing or
     * unknown — e.g. a setup type saved before the `algorithm` field
     * existed. This keeps existing installs behaving exactly as before
     * until an admin explicitly picks a different algorithm.
     */
    public static function resolve(?string $key): BuySetupAlgorithm
    {
        $class = self::ALGORITHMS[$key] ?? self::ALGORITHMS[self::DEFAULT_KEY];

        return app($class);
    }
}
