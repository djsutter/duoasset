<?php

namespace App\Services\Stocks\Algorithms;

use App\Services\Stocks\StockBuySetupResult;

/**
 * Contract for a Stock Buy Setup detection algorithm.
 *
 * A setup type (heartbeat_consolidation_spike, range_compression_breakout,
 * floor_reversal_accumulation, early_breakout_followthrough, or any custom
 * type added via the Web UI) selects which algorithm actually runs via its
 * own `algorithm` config key — independent of the setup type's key/label.
 * This lets an admin, for example, run the "Floor Reversal / Accumulation"
 * algorithm under a custom-named setup type. See BuySetupAlgorithmRegistry.
 */
interface BuySetupAlgorithm
{
    /**
     * Unique, stable registry key for this algorithm (snake_case). Stored
     * in a setup type's `algorithm` config value.
     */
    public function key(): string;

    /**
     * Human-readable label for the config UI's algorithm dropdown.
     */
    public function label(): string;

    /**
     * Detect the setup for one symbol given an ascending-date series of
     * daily OHLCV bars. Returns null when the symbol doesn't qualify —
     * call lastRejectionReason() for a human-readable explanation.
     *
     * @param  array<int, array{date:string, open:?float, high:?float, low:?float, close:?float, volume:?int}>  $bars
     * @param  array<int, array<string, mixed>>  $benchmarkBars
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $typeConfig  The resolved setup-type config (thresholds, market-cap range, etc.)
     * @param  string  $setupType  The setup type's own key — persisted on the result, independent of this algorithm's key.
     */
    public function detect(array $bars, array $benchmarkBars, array $context, array $typeConfig, string $setupType): ?StockBuySetupResult;

    /**
     * Human-readable reason for the most recent non-match.
     */
    public function lastRejectionReason(): ?string;
}
