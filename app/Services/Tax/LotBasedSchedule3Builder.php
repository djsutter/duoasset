<?php

namespace App\Services\Tax;

use App\Data\Tax\Schedule3\Schedule3AssetData;
use App\Data\Tax\Schedule3\Schedule3BuilderInterface;
use App\Data\Tax\Schedule3\Schedule3Data;
use App\Data\Tax\Schedule3\Schedule3DispositionData;
use App\Enums\AcbEventType;
use App\Models\LotDisposition;
use App\Queries\Tax\Schedule3\LotDispositionQuery;
use App\Queries\Tax\Schedule3\SuperficialLossQuery;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;

final class LotBasedSchedule3Builder implements Schedule3BuilderInterface
{
    public function build(int $taxYear): Schedule3Data
    {
        $assetRows = $this->buildLotRows($taxYear);

        return $this->assembleDto(
            taxYear: $taxYear,
            method: 'lot',
            assetRows: $assetRows,
        );
    }

    private function assembleDto(
        int $taxYear,
        string $method,
        array $assetRows,
    ): Schedule3Data {
        $reportingCurrency = getReportingCurrency();
        $totalProceeds = array_reduce($assetRows, fn ($carry, $row) => $carry->add($row->proceeds), Money::zero($reportingCurrency));
        $totalCost = array_reduce($assetRows, fn ($carry, $row) => $carry->add($row->acb), Money::zero($reportingCurrency));
        $totalGain = array_reduce($assetRows, fn ($carry, $row) => $carry->add($row->gain), Money::zero($reportingCurrency));
        $totalDeniedLoss = array_reduce($assetRows, fn ($carry, $row) => $carry->add($row->denied_loss_sum), Money::zero($reportingCurrency));

        return new Schedule3Data(
            tax_year: $taxYear,
            method: $method,
            total_proceeds: $totalProceeds,
            total_cost: $totalCost,
            total_gain_or_loss: $totalGain,
            total_denied_loss: $totalDeniedLoss,
            asset_rows: $assetRows,
        );
    }

    private function buildLotRows(int $taxYear): array
    {
        $from = CarbonImmutable::create($taxYear, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($taxYear, 12, 31)->endOfDay();

        return LotDisposition::query()
            ->whereBetween('disposed_at', [$from, $to])
            ->selectRaw('
                asset_code,
                SUM(proceeds) AS total_proceeds,
                SUM(acb_allocated - denied_loss_amount) AS total_tax_acb
            ')
            ->groupBy('asset_code')
            ->get()
            ->map(function ($row) use ($taxYear) {
                $proceeds = Money::fromDecimal($row->total_proceeds, 'CAD');
                $acb = Money::fromDecimal($row->total_tax_acb, 'CAD');

                return new Schedule3AssetData(
                    tax_year: $taxYear,
                    asset_code: $row->asset_code,
                    description: "Cryptocurrency – {$row->asset_code}",
                    proceeds: $proceeds,
                    acb: $acb,
                    outlays: Money::zero('CAD'),
                    gain: $proceeds->subtract($acb),
                    dispositions: $this->dispositionsForAsset($taxYear, $row->asset_code)
                );
            })
            ->all();
    }

    private function dispositionsForAsset(
        int $year,
        string $assetCode
    ): array {
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($year, 12, 31)->endOfDay();

        $disposalRows = LotDisposition::query()
            ->join('acb_events', 'acb_events.id', '=', 'lot_dispositions.acb_event_id')
            ->where('acb_events.event_type', AcbEventType::Disposal)
            ->whereBetween('acb_events.event_at', [$from, $to])
            ->where('lot_dispositions.asset_code', $assetCode)
            ->selectRaw('
                acb_events.id AS acb_event_id,
                acb_events.event_at AS disposed_at,
                acb_events.event_type AS disposition_type,
                lot_dispositions.asset_code,
                SUM(lot_dispositions.disposed_quantity) AS total_quantity,
                SUM(lot_dispositions.proceeds) AS total_proceeds,
                SUM(lot_dispositions.acb_allocated - lot_dispositions.denied_loss_amount) AS total_tax_acb,
                SUM(lot_dispositions.denied_loss_amount) AS total_denied_loss
            ')
            ->groupBy(
                'acb_events.id',
                'acb_events.event_at',
                'acb_events.event_type',
                'lot_dispositions.asset_code'
            )
            ->get();

        $dispositions = [];
        $reportingCurrency = getReportingCurrency();

        foreach ($disposalRows as $row) {
            $proceeds = Money::fromDecimal($row->total_proceeds, $reportingCurrency);
            $acb = Money::fromDecimal($row->total_tax_acb, $reportingCurrency);
            $deniedLoss = Money::fromDecimal($row->total_denied_loss, $reportingCurrency);
            $gain = $proceeds->subtract($acb);
            $isSuperficialLoss = bccomp((string) $row->total_denied_loss, '0', 8) === 1;
            $lotAllocations = LotDispositionQuery::forAcbEvent($row->acb_event_id);
            $superficialLoss = SuperficialLossQuery::forAcbEvent($row->acb_event_id);

            $dispositions[] = Schedule3DispositionData::internalCreate(
                acb_event_id: $row->acb_event_id,
                date: CarbonImmutable::parse($row->disposed_at),
                quantity: AssetQuantity::fromDecimal($row->total_quantity, $row->asset_code),
                proceeds: $proceeds,
                acb_reportable: $acb,
                outlays: Money::zero($reportingCurrency),
                capital_gain_loss: $gain,
                acb_allocated: Money::zero($reportingCurrency), // fix
                denied_loss: $deniedLoss,
                capital_gain_loss_before_denial: Money::zero($reportingCurrency), // fix
                disposition_type: $row->disposition_type,
                is_superficial_loss: $isSuperficialLoss,
                lot_allocations: $lotAllocations,
                superficial_loss: $superficialLoss,
                adjustments: [],
            );
        }

        return $dispositions;
    }
}
