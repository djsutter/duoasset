<?php

namespace App\Services\Tax;

use App\Data\Tax\Schedule3\Schedule3AssetData;
use App\Data\Tax\Schedule3\Schedule3BuilderInterface;
use App\Data\Tax\Schedule3\Schedule3Data;
use App\Data\Tax\Schedule3\Schedule3DispositionFactory;
use App\Data\Tax\TaxPoolLedgerEntryData;
use App\Enums\TaxPoolLedgerEntryType;
use App\Models\TaxPoolDisposition;
use App\Types\Money;
use Carbon\CarbonImmutable;

final class PoolBasedSchedule3Builder implements Schedule3BuilderInterface
{
    public function build(int $taxYear): Schedule3Data
    {
        $assetRows = $this->buildPoolRows($taxYear);

        return $this->assembleDto(
            taxYear: $taxYear,
            method: 'pool',
            assetRows: $assetRows,
        );
    }

    private function assembleDto(int $taxYear, string $method, array $assetRows): Schedule3Data
    {
        $reportingCurrency = getReportingCurrency();
        $totalProceeds = array_reduce($assetRows, fn ($carry, $row) => $carry->add($row->proceeds), Money::zero($reportingCurrency));
        $totalAcbAllocated = array_reduce($assetRows, fn ($carry, $row) => $carry->add($row->acb_allocated), Money::zero($reportingCurrency));
        $totalDeniedLoss = array_reduce($assetRows, fn ($carry, $row) => $carry->add($row->denied_loss_sum), Money::zero($reportingCurrency));
        $totalAcbReportable = $totalAcbAllocated->subtract($totalDeniedLoss);
        $totalGainOrLoss = $totalProceeds->subtract($totalAcbReportable);

        return new Schedule3Data(
            tax_year: $taxYear,
            method: $method,
            total_proceeds: $totalProceeds,
            total_acb_allocated: $totalAcbAllocated,
            total_acb_reportable: $totalAcbReportable,
            total_capital_gain_loss: $totalGainOrLoss,
            total_denied_loss: $totalDeniedLoss,
            asset_rows: $assetRows,
        );
    }

    private function buildPoolRows(int $taxYear): array
    {
        $from = CarbonImmutable::create($taxYear, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($taxYear, 12, 31)->endOfDay();

        // Get all assets that had dispositions this year
        $assetCodes = TaxPoolDisposition::query()
            ->whereBetween('disposition_date', [$from, $to])
            ->distinct()
            ->pluck('asset_code');

        $rows = [];
        foreach ($assetCodes as $assetCode) {
            $rows[] = $this->buildAssetRow($taxYear, $assetCode);
        }

        return $rows;
    }

    public function buildAssetRow(int $taxYear, string $assetCode): Schedule3AssetData
    {
        $ledger = app(TaxPoolLedgerBuilder::class)
            ->buildForAssetUpToDate($assetCode, CarbonImmutable::create($taxYear, 12, 31)->endOfDay());

        $reportingCurrency = getReportingCurrency();
        $proceedsSum = Money::zero($reportingCurrency);
        $acbAllocated = Money::zero($reportingCurrency);
        $deniedLossSum = Money::zero($reportingCurrency);
        $dispositions = [];

        foreach ($ledger as $entry) {
            if (! $this->isRelevantDisposition($entry, $taxYear)) {
                continue;
            }
            $dispositions[] = Schedule3DispositionFactory::fromPoolEntry($entry);
            $proceedsSum = $proceedsSum->add($entry->proceeds);
            $acbAllocated = $acbAllocated->add($entry->acb_allocated);
            $deniedLossSum = $deniedLossSum->add($entry->denied_loss ?? Money::zero($reportingCurrency));
        }

        $acbReportable = $acbAllocated->subtract($deniedLossSum);
        $gainOrLoss = $proceedsSum->subtract($acbReportable);

        return new Schedule3AssetData(
            tax_year: $taxYear,
            asset_code: $assetCode,
            description: "Cryptocurrency – {$assetCode}",
            proceeds: $proceedsSum,
            acb_allocated: $acbAllocated,
            acb_reportable: $acbReportable,
            outlays: Money::zero($reportingCurrency),
            capital_gain_loss: $gainOrLoss,
            dispositions: $dispositions,
            denied_loss_sum: $deniedLossSum,
        );
    }

    private function isRelevantDisposition(TaxPoolLedgerEntryData $entry, int $taxYear): bool
    {
        return $entry->event_type === TaxPoolLedgerEntryType::Disposition
            && $entry->event_date->year === $taxYear;
    }
}
