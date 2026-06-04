<?php

namespace App\Queries\Tax\Schedule3;

use App\Data\Tax\Schedule3\Schedule3LotAllocationData;
use App\Models\LotDisposition;
use App\Types\Money;

final class LotDispositionQuery
{
    /**
     * @return Schedule3LotAllocationData[]
     */
    public static function forAcbEvent(int $acbEventId): array
    {
        return LotDisposition::query()
            ->join('lots', 'lots.id', '=', 'lot_dispositions.lot_id')
            ->select('lot_dispositions.*') // critical
            ->with('lot') // still hydrate Lot separately
            ->where('lot_dispositions.acb_event_id', $acbEventId)
            ->orderBy('lots.acquired_at')
            ->get()
            ->map(function (LotDisposition $ld) {
                $lot = $ld->lot;

                return new Schedule3LotAllocationData(
                    lot_id: $ld->lot_id,
                    acb_event_id: $ld->acb_event_id,
                    acquired_at: $lot->acquired_at,
                    acquired_quantity: $lot->original_quantity,
                    acquired_unit_cost: $lot->original_quantity->isPositive()
                        ? $lot->original_acb_amount->divide($lot->original_quantity->toDecimal())
                        : Money::zero('CAD'),
                    disposed_quantity: $ld->disposed_quantity,
                    acb_used_amount: $ld->acb_allocated,
                    remaining_quantity: $lot->remaining_quantity,
                );
            })
            ->all();
    }
}
