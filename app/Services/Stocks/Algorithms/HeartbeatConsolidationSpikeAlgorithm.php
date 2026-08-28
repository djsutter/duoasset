<?php

namespace App\Services\Stocks\Algorithms;

use App\Services\Stocks\StockBuySetupResult;
use App\Services\Stocks\StockBuySetupScanner;

/**
 * Thin adapter around the original, unchanged detection logic: a tight
 * multi-week consolidation base followed by a rare 52-week/104-week
 * high-volume spike. The actual algorithm still lives in
 * StockBuySetupScanner::evaluate() (kept there to preserve its extensive
 * existing test coverage); this class only makes it participate in the
 * same BuySetupAlgorithmRegistry as the other three algorithms.
 */
class HeartbeatConsolidationSpikeAlgorithm implements BuySetupAlgorithm
{
    public function __construct(private StockBuySetupScanner $scanner)
    {
    }

    public function key(): string
    {
        return 'heartbeat_consolidation_spike';
    }

    public function label(): string
    {
        return 'Heartbeat consolidation + spike';
    }

    public function detect(array $bars, array $benchmarkBars, array $context, array $typeConfig, string $setupType): ?StockBuySetupResult
    {
        return $this->scanner->evaluate($bars, $benchmarkBars, $context, $typeConfig, $setupType);
    }

    public function lastRejectionReason(): ?string
    {
        return $this->scanner->lastRejectionReason();
    }
}
