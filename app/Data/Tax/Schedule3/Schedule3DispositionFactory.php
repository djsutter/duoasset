<?php

namespace App\Data\Tax\Schedule3;

use App\Data\Tax\TaxPoolLedgerEntryData;
use App\Enums\AcbEventType;
use App\Queries\Tax\Schedule3\LotDispositionQuery;
use App\Queries\Tax\Schedule3\SuperficialLossQuery;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;

final class Schedule3DispositionFactory
{
    public static function fromPoolEntry(TaxPoolLedgerEntryData $entry): Schedule3DispositionData
    {
        $reportingCurrency = getReportingCurrency();

        $deniedLoss = $entry->denied_loss ?? Money::zero($reportingCurrency);
        $acbAllocated = $entry->acb_allocated;
        $acbReportable = $acbAllocated->subtract($deniedLoss);
        $capitalGainLoss = $entry->capital_gain_loss_after_denial;

        // HARD INVARIANT
        if (! $entry->proceeds->subtract($acbReportable)->equalsWithTolerance($capitalGainLoss, '0.000001')) {
            throw new \LogicException('Schedule3 invariant violation (pool)');
        }

        return Schedule3DispositionData::internalCreate(
            acb_event_id: $entry->source_event_id,
            date: $entry->event_date,
            quantity: $entry->quantity_delta->negated(),
            proceeds: $entry->proceeds,
            acb_reportable: $acbReportable,
            outlays: Money::zero($reportingCurrency),
            capital_gain_loss: $capitalGainLoss,
            acb_allocated: $acbAllocated,
            denied_loss: $deniedLoss,
            capital_gain_loss_before_denial: $entry->capital_gain_loss_before_denial,
            disposition_type: AcbEventType::Disposal->value,
            is_superficial_loss: $deniedLoss->isPositive(),
            lot_allocations: [],
            superficial_loss: self::buildPoolSuperficialLossData($entry),
            adjustments: [],
        );
    }

    public static function fromLotRow(object $row): Schedule3DispositionData
    {
        $reportingCurrency = getReportingCurrency();

        $proceeds = Money::fromDecimal($row->total_proceeds, $reportingCurrency);
        $acbReportable = Money::fromDecimal($row->total_tax_acb, $reportingCurrency);
        $deniedLoss = Money::fromDecimal($row->total_denied_loss, $reportingCurrency);

        // Reconstruct allocated ACB for diagnostics
        $acbAllocated = $acbReportable->add($deniedLoss);

        $capitalGainLoss = $proceeds->subtract($acbReportable);

        return Schedule3DispositionData::internalCreate(
            acb_event_id: $row->acb_event_id,
            date: CarbonImmutable::parse($row->disposed_at),
            quantity: AssetQuantity::fromDecimal($row->total_quantity, $row->asset_code),
            proceeds: $proceeds,
            acb_reportable: $acbReportable,
            outlays: Money::zero($reportingCurrency),
            capital_gain_loss: $capitalGainLoss,
            acb_allocated: $acbAllocated,
            denied_loss: $deniedLoss,
            capital_gain_loss_before_denial: null, // not available in aggregate
            disposition_type: $row->disposition_type,
            is_superficial_loss: $deniedLoss->isPositive(),
            lot_allocations: LotDispositionQuery::forAcbEvent($row->acb_event_id),
            superficial_loss: SuperficialLossQuery::forAcbEvent($row->acb_event_id),
            adjustments: [],
        );
    }

    private static function buildPoolSuperficialLossData(TaxPoolLedgerEntryData $entry): ?Schedule3SuperficialLossData
    {
        if (! $entry->denied_loss?->isPositive()) {
            return null;
        }

        return new Schedule3SuperficialLossData(
            acb_event_id: $entry->source_event_id,
            capital_gain_loss_before_denial: $entry->capital_gain_loss_before_denial,
            denied_loss_amount: $entry->denied_loss,
            capital_gain_loss_after_denial: $entry->capital_gain_loss_after_denial,
            denial_reason: 'Superficial loss rules applied',
            window_start: null,
            window_end: null,
            resolution_type: null,
            replacement_acb_event_id: null,
        );
    }
}
