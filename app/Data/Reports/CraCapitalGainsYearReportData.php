<?php

namespace App\Data\Reports;

final class CraCapitalGainsYearReportData
{
    /** @param CraCapitalGainsDispositionData[] $dispositions */
    public function __construct(
        public readonly int $tax_year,
        public readonly CraCapitalGainsReportSummaryData $summary,
        public readonly array $dispositions
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
