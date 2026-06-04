<?php

namespace App\Data\Reports;

final class CraCapitalGainsAssetReportData
{
    /** @param CraCapitalGainsYearReportData[] $years */
    public function __construct(
        public readonly string $asset_code,
        public readonly string $asset_name,
        public readonly array $years,
    ) {}

    public function hasData(): bool
    {
        foreach ($this->years as $year) {
            if ($year->hasData()) {
                return true;
            }
        }

        return false;
    }
}
