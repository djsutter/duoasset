<?php

namespace App\Services\MoneyFlow;

use Carbon\CarbonInterface;

/**
 * Outcome of one moneyflow:update run — what the command reports.
 */
final class SectorMoneyFlowRunSummary
{
    /**
     * @param  array<int, string>  $publishedSectors  sector keys persisted
     * @param  array<string, string>  $skipped  sector key => reason skipped
     * @param  array<int, array{path?: string, status?: int, body?: string}>  $providerErrors
     */
    public function __construct(
        public readonly string $interval,
        public readonly string $capturedSlot,
        public readonly string $snapshotDate,
        public readonly CarbonInterface $capturedAt,
        public readonly array $publishedSectors,
        public readonly array $skipped,
        public readonly array $providerErrors,
    ) {}

    public function publishedCount(): int
    {
        return count($this->publishedSectors);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function hasPublished(): bool
    {
        return $this->publishedSectors !== [];
    }
}
