<?php

namespace App\Services\Reports;

use App\Contracts\Reports\CapitalGainsReportService;
use App\Data\Reports\CapitalGainRowData;
use App\Data\Reports\CapitalGainsAssetTotalData;
use App\Data\Reports\CapitalGainsYearTotalData;
use App\Data\Reports\CraCapitalGainsAssetReportData;
use App\Data\Reports\CraCapitalGainsAssetTotalData;
use App\Data\Reports\CraCapitalGainsDispositionData;
use App\Data\Reports\CraCapitalGainsReportData;
use App\Data\Reports\CraCapitalGainsReportSummaryData;
use App\Data\Reports\CraCapitalGainsYearReportData;
use App\Data\Reports\CraCapitalGainsYearTotalData;
use App\Models\Asset;
use App\Types\Money;

/**
 * Generate a CRA-style capital gains report.
 */
final class CraCapitalGainsReportService implements CapitalGainsReportService
{
    /** @var string[] */
    protected array $assetCodes = [];

    /** @var int[] */
    protected array $taxYears = [];

    public function __construct(
        private readonly LedgerCapitalGainsReportService $ledgerService
    ) {}

    public function forAssets(array $assetCodes): self
    {
        $this->assetCodes = array_values(array_unique($assetCodes));
        $this->ledgerService->forAssets($assetCodes);

        return $this;
    }

    public function forTaxYears(array $years): self
    {
        $this->taxYears = array_values(array_unique($years));
        $this->ledgerService->forTaxYears($years);

        return $this;
    }

    /**
     * Generate a CRA-style capital gains report.
     */
    public function build(): CraCapitalGainsReportData
    {
        $this->assertConfigured();

        /** @var array<string, array<int, array>> $groupedCraRows */
        $groupedCraRows = [];

        $assets = Asset::whereIn('asset_code', $this->assetCodes)
            ->where('asset_code', '!=', getReportingCurrency())
            ->orderBy('asset_code')
            ->get();

        foreach ($assets as $asset) {
            foreach ($this->taxYears as $year) {

                $ledgerRows = $this->ledgerService->ledgerRows($asset, $year);

                if ($ledgerRows === []) {
                    continue;
                }

                foreach ($ledgerRows as $row) {
                    $craRow = $this->normalizeRowForCra($row);

                    $groupedCraRows[$asset->asset_code][$year][] = $craRow;
                }
            }
        }

        $assetReports = [];

        foreach ($groupedCraRows as $assetCode => $years) {
            $asset = $assets->firstWhere('asset_code', $assetCode);

            $yearReports = [];

            foreach ($years as $year => $craRows) {
                $yearReports[] = new CraCapitalGainsYearReportData(
                    tax_year: $year,
                    summary: CraCapitalGainsReportSummaryData::fromCraRows($craRows),
                    dispositions: array_map(
                        fn (array $row) => CraCapitalGainsDispositionData::fromCraNormalized($row, $asset),
                        $craRows
                    )
                );
            }

            $assetReports[] = new CraCapitalGainsAssetReportData(
                asset_code: $asset->asset_code,
                asset_name: $asset->currency->name,
                years: $yearReports
            );
        }

        $assetTotals = $this->buildAssetTotals($assetReports);
        $yearTotals = $this->buildYearTotals($assetReports);

        return new CraCapitalGainsReportData(
            assets: $assetReports,
            asset_totals: $assetTotals,
            year_totals: $yearTotals,
        );
    }

    /**
     * @param  CraCapitalGainsAssetReportData[]  $assets
     * @return CapitalGainsAssetTotalData[]
     */
    private function buildAssetTotals(array $assets): array
    {
        $currency = getReportingCurrency();
        $totals = [];

        foreach ($assets as $assetReport) {
            $proceeds = Money::zero($currency);
            $acb = Money::zero($currency);
            $gain = Money::zero($currency);
            $taxable = Money::zero($currency);

            foreach ($assetReport->years as $yearReport) {
                $summary = $yearReport->summary;
                $proceeds = $proceeds->add($summary->proceeds_of_disposition);
                $acb = $acb->add($summary->adjusted_cost_base);
                $gain = $gain->add($summary->net_capital_gain_loss);
                $taxable = $taxable->add($summary->taxable_capital_gain);
            }

            $totals[] = new CraCapitalGainsAssetTotalData(
                asset_code: $assetReport->asset_code,
                asset_name: $assetReport->asset_name,
                proceeds_of_disposition: $proceeds,
                adjusted_cost_base: $acb,
                outlays_and_expenses: Money::zero($currency),
                capital_gain_loss: $gain,
                taxable_capital_gain: $taxable,
            );
        }

        return $totals;
    }

    /**
     * @param  CraCapitalGainsAssetReportData[]  $assets
     * @return CapitalGainsYearTotalData[]
     */
    private function buildYearTotals(array $assets): array
    {
        $currency = getReportingCurrency();
        $years = [];

        foreach ($assets as $assetReport) {
            foreach ($assetReport->years as $yearReport) {
                $year = $yearReport->tax_year;

                $years[$year] ??= [
                    'proceeds' => Money::zero($currency),
                    'acb' => Money::zero($currency),
                    'gain' => Money::zero($currency),
                    'taxable' => Money::zero($currency),
                ];

                $summary = $yearReport->summary;

                $years[$year]['proceeds'] =
                    $years[$year]['proceeds']->add($summary->proceeds_of_disposition);

                $years[$year]['acb'] =
                    $years[$year]['acb']->add($summary->adjusted_cost_base);

                $years[$year]['gain'] =
                    $years[$year]['gain']->add($summary->net_capital_gain_loss);

                $years[$year]['taxable'] =
                    $years[$year]['taxable']->add($summary->taxable_capital_gain);
            }
        }

        krsort($years);

        return array_map(
            fn (int $year, array $totals) => new CraCapitalGainsYearTotalData(
                tax_year: $year,
                proceeds_of_disposition: $totals['proceeds'],
                adjusted_cost_base: $totals['acb'],
                outlays_and_expenses: Money::zero($currency),
                capital_gain_loss: $totals['gain'],
                taxable_capital_gain: $totals['taxable'],
            ),
            array_keys($years),
            $years
        );
    }

    /**
     * Normalize a ledger-style capital gain row into CRA semantics.
     */
    public function normalizeRowForCra(CapitalGainRowData $row): array
    {
        // CRA expects positive values
        $proceeds = $row->proceeds->abs();
        $acb = $row->acb_allocated->abs();
        $expenses = $row->expenses->abs();

        // CRA gain/loss is recomputed, never reused
        $gainOrLoss = $proceeds
            ->subtract($acb)
            ->subtract($expenses);

        return [
            'disposed_at' => $row->date,
            'asset_code' => $row->asset_code,
            'quantity' => $row->quantity_disposed,
            'proceeds' => $proceeds,
            'acb' => $acb,
            'expenses' => $expenses,
            'gain_or_loss' => $gainOrLoss,
            'taxable_amount' => $gainOrLoss->multiply('0.5'),
            'tx_id' => $row->tx_id,
            'tax_year' => $row->tax_year,
        ];
    }

    private function assertConfigured(): void
    {
        if ($this->assetCodes === []) {
            throw new \LogicException(
                'CraCapitalGainsReportService requires an asset code before build().'
            );
        }
    }
}
