<?php

namespace App\Data\Reports;

final class LedgerCapitalGainsYearReportData
{
    /** @param LedgerCapitalGainsDispositionData[] $dispositions */
    public function __construct(
        public readonly int $tax_year,
        public readonly LedgerCapitalGainsSummaryData $summary,
        public readonly array $dispositions,
    ) {}

    public function hasDisposals(): bool
    {
        return ! empty($this->dispositions);
    }

    public function hasData(): bool
    {
        return $this->hasDisposals();
    }
}
