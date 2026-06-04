<?php

namespace App\Services\Reports;

use App\Contracts\Reports\CapitalGainsReportService;
use App\Data\Reports\CapitalGainRowData;
use App\Data\Reports\CapitalGainsAssetTotalData;
use App\Data\Reports\CapitalGainsYearTotalData;
use App\Data\Reports\LedgerCapitalGainsAssetReportData;
use App\Data\Reports\LedgerCapitalGainsDispositionData;
use App\Data\Reports\LedgerCapitalGainsReportData;
use App\Data\Reports\LedgerCapitalGainsSummaryData;
use App\Data\Reports\LedgerCapitalGainsYearReportData;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Types\Money;

/**
 * Generate a CRA-style capital gains report.
 */
final class LedgerCapitalGainsReportService implements CapitalGainsReportService
{
    /** @var string[] */
    protected array $assetCodes = [];

    /** @var int[] */
    protected array $taxYears = [];

    /** @var array<string, CapitalGainRowData[]> */
    protected array $cachedRows = [];

    public function forAssets(array $assetCodes): self
    {
        $this->assetCodes = array_values(array_unique($assetCodes));

        return $this;
    }

    public function forTaxYears(array $years): self
    {
        $this->taxYears = array_values(array_unique($years));

        return $this;
    }

    public function build(): LedgerCapitalGainsReportData
    {
        $this->assertConfigured();

        $assetReports = [];

        foreach ($this->resolveAssets() as $asset) {
            $yearReports = [];

            foreach ($this->taxYears as $year) {
                $rows = $this->ledgerRows($asset, $year);

                $yearReport = new LedgerCapitalGainsYearReportData(
                    tax_year: $year,
                    summary: LedgerCapitalGainsSummaryData::fromLedgerRows($rows),
                    dispositions: array_map(
                        fn (CapitalGainRowData $row) => LedgerCapitalGainsDispositionData::fromLedgerRow($row, $asset),
                        $rows
                    )
                );

                // Keep only years that actually have data
                if ($yearReport->hasData()) {
                    $yearReports[] = $yearReport;
                }
            }

            // Skip assets that have no remaining years
            if ($yearReports === []) {
                continue;
            }

            $assetReports[] = new LedgerCapitalGainsAssetReportData(
                asset_code: $asset->asset_code,
                asset_name: $asset->currency->name,
                years: $yearReports
            );
        }

        $assetTotals = $this->buildAssetTotals($assetReports);
        $yearTotals = $this->buildYearTotals($assetReports);

        return new LedgerCapitalGainsReportData(
            assets: $assetReports,
            asset_totals: $assetTotals,
            year_totals: $yearTotals,
        );
    }

    /**
     * @param  LedgerCapitalGainsAssetReportData[]  $assets
     * @return CapitalGainsAssetTotalData[]
     */
    private function buildAssetTotals(array $assets): array
    {
        $totals = [];

        foreach ($assets as $asset) {
            $currency = getReportingCurrency();

            $proceeds = Money::zero($currency);
            $acb = Money::zero($currency);
            $gain = Money::zero($currency);
            $taxable = Money::zero($currency);

            foreach ($asset->years as $year) {
                $summary = $year->summary;

                $proceeds = $proceeds->add($summary->total_proceeds);
                $acb = $acb->add($summary->total_acb);
                $gain = $gain->add($summary->total_gain_or_loss);
                $taxable = $taxable->add($summary->taxable_amount);
            }

            $totals[] = new CapitalGainsAssetTotalData(
                asset_code: $asset->asset_code,
                asset_name: $asset->asset_name,
                total_proceeds: $proceeds,
                total_acb: $acb,
                total_gain_or_loss: $gain,
                taxable_amount: $taxable,
            );
        }

        return $totals;
    }

    /**
     * @param  LedgerCapitalGainsAssetReportData[]  $assets
     * @return CapitalGainsYearTotalData[]
     */
    private function buildYearTotals(array $assets): array
    {
        $currency = getReportingCurrency();
        $yearMap = [];

        foreach ($assets as $asset) {
            foreach ($asset->years as $year) {
                $taxYear = $year->tax_year;
                $summary = $year->summary;

                $yearMap[$taxYear] ??= [
                    'proceeds' => Money::zero($currency),
                    'acb' => Money::zero($currency),
                    'gain' => Money::zero($currency),
                    'taxable' => Money::zero($currency),
                ];

                $yearMap[$taxYear]['proceeds']
                    = $yearMap[$taxYear]['proceeds']->add($summary->total_proceeds);

                $yearMap[$taxYear]['acb']
                    = $yearMap[$taxYear]['acb']->add($summary->total_acb);

                $yearMap[$taxYear]['gain']
                    = $yearMap[$taxYear]['gain']->add($summary->total_gain_or_loss);

                $yearMap[$taxYear]['taxable']
                    = $yearMap[$taxYear]['taxable']->add($summary->taxable_amount);
            }
        }

        ksort($yearMap);

        return array_map(
            fn (int $year, array $totals) => new CapitalGainsYearTotalData(
                tax_year: $year,
                total_proceeds: $totals['proceeds'],
                total_acb: $totals['acb'],
                total_gain_or_loss: $totals['gain'],
                taxable_amount: $totals['taxable'],
            ),
            array_keys($yearMap),
            $yearMap
        );
    }

    /** @return Asset[] */
    private function resolveAssets(): array
    {
        return Asset::whereIn('asset_code', $this->assetCodes)
            ->where('asset_code', '!=', getReportingCurrency())
            ->orderBy('asset_code')
            ->get()
            ->all();
    }

    /** @return CapitalGainRowData[] */
    public function ledgerRows(Asset $asset, int $year): array
    {
        $cacheKey = "{$asset->asset_code}:{$year}";

        if (isset($this->cachedRows[$cacheKey])) {
            return $this->cachedRows[$cacheKey];
        }

        $query = AcbEvent::query()
            ->where('asset_code', $asset->asset_code)
            ->where('event_type', 'disposal')
            ->whereYear('event_at', $year)
            ->orderBy('event_at')
            ->orderBy('id');

        return $this->cachedRows[$cacheKey] = $query
            ->get()
            ->map(fn (AcbEvent $e) => CapitalGainRowData::fromAcbEvent($e))
            ->all();
    }

    private function assertConfigured(): void
    {
        if ($this->assetCodes === [] || $this->taxYears === []) {
            throw new \LogicException(
                'LedgerCapitalGainsReportService requires assets and tax years before build().'
            );
        }
    }
}
