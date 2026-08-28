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
     * Algorithms whose setups are, by design, bottoming/reversal patterns
     * rather than continuations near highs. StockBuySetupScorer uses this
     * to reinterpret the `ma_alignment` component (previously "intentionally
     * deferred" — see README_setup.md), since rewarding a full bullish
     * 50>150>200 stack would penalize the very decline-then-floor shape
     * these algorithms look for.
     *
     * @var array<int, string>
     */
    private const REVERSAL_STYLE_ALGORITHMS = [
        'floor_reversal_accumulation',
    ];

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
     * Whether the given algorithm key detects a bottoming/reversal pattern
     * (price below its long-term moving averages by design) rather than a
     * continuation near highs. See REVERSAL_STYLE_ALGORITHMS.
     */
    public static function isReversalStyle(string $key): bool
    {
        return in_array($key, self::REVERSAL_STYLE_ALGORITHMS, true);
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
