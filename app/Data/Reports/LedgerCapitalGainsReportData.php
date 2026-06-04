<?php

namespace App\Data\Reports;

final class LedgerCapitalGainsReportData
{
    /**
     * @param  LedgerCapitalGainsAssetReportData[]  $assets
     * @param  CapitalGainsAssetTotalData[]  $asset_totals
     * @param  CapitalGainsYearTotalData[]  $year_totals
     */
    public function __construct(
        public readonly array $assets,
        public readonly array $asset_totals,
        public readonly array $year_totals,
    ) {}
}
