<?php

namespace App\Domain\Tax\Continuity;

use App\Domain\Tax\Continuity\Data\AssetContinuityData;
use App\Domain\Tax\Continuity\Data\TaxYearContinuityReportData;
use Carbon\Carbon;

final class TaxYearContinuityService
{
    public function __construct(
        protected TaxAssetStateBuilderInterface $builder,
    ) {}

    public function buildForAssetAndTaxYear(string $assetCode, int $taxYear): TaxYearContinuityReportData
    {
        $assets = [];

        $assets[] = $this->buildForAsset($assetCode, $taxYear);

        return TaxYearContinuityReportData::fromAssets(
            $taxYear,
            $assets,
        );
    }

    public function buildForTaxYear(int $taxYear): TaxYearContinuityReportData
    {
        $assets = [];

        foreach ($this->builder->getActiveAssets() as $assetCode) {
            $assets[] = $this->buildForAsset($assetCode, $taxYear);
        }

        return TaxYearContinuityReportData::fromAssets(
            $taxYear,
            $assets,
        );
    }

    protected function buildForAsset(
        string $assetCode,
        int $taxYear,
    ): AssetContinuityData {
        $startOfYear = Carbon::create($taxYear, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($taxYear, 12, 31)->endOfDay();

        $openingState = $this->builder->buildUpToDate(
            $assetCode,
            $startOfYear->copy()->subSecond()
        );

        $closingState = $this->builder->buildUpToDate(
            $assetCode,
            $endOfYear
        );

        $activity = $this->builder->buildBetweenDates(
            $assetCode,
            $startOfYear,
            $endOfYear
        );

        return AssetContinuityData::fromSnapshots(
            $assetCode,
            $openingState,
            $activity,
            $closingState,
        );
    }
}
