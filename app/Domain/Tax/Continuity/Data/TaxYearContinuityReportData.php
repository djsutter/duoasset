<?php

namespace App\Domain\Tax\Continuity\Data;

use App\Types\Money;

final class TaxYearContinuityReportData
{
    /**
     * @param  AssetContinuityData[]  $assets
     */
    public function __construct(
        public readonly int $tax_year,
        public readonly array $assets,

        public readonly Money $total_opening_acb,
        public readonly Money $total_closing_acb,
        public readonly Money $total_realized_gain_before_denial,
    ) {}

    public static function fromAssets(
        int $taxYear,
        array $assets,
    ): self {
        $reportingCurrency = getReportingCurrency();
        $totalOpeningAcb = Money::zero($reportingCurrency);
        $totalClosingAcb = Money::zero($reportingCurrency);
        $totalRealizedGain = Money::zero($reportingCurrency);

        foreach ($assets as $asset) {
            $totalOpeningAcb = $totalOpeningAcb->add($asset->opening_acb);
            $totalClosingAcb = $totalClosingAcb->add($asset->closing_acb);
            $totalRealizedGain = $totalRealizedGain->add($asset->realized_gain_before_denial);
        }

        return new self(
            tax_year: $taxYear,
            assets: $assets,
            total_opening_acb: $totalOpeningAcb,
            total_closing_acb: $totalClosingAcb,
            total_realized_gain_before_denial: $totalRealizedGain,
        );
    }
}
