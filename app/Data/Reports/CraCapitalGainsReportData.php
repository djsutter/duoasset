<?php

namespace App\Data\Reports;

final class CraCapitalGainsReportData
{
    /** @param CraCapitalGainsAssetReportData[] $assets */
    public function __construct(
        public readonly array $assets,
        public readonly array $asset_totals,
        public readonly array $year_totals,
    ) {}
}
