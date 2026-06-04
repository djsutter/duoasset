<?php

namespace App\Services\Reports\Acb;

use App\Data\Reports\AssetAcbAuditRowData;
use App\Types\AssetQuantity;

final class SuperficialLossAdjustmentCalculator
{
    /**
     * Determine whether a disposition results in a superficial loss and,
     * if so, return an ACB adjustment row to be inserted immediately after it.
     *
     * @param  AssetAcbAuditRowData  $disposition  The disposition row being evaluated
     * @param  AssetAcbAuditRowData[]  $rows  Full audit ledger
     * @param  int  $index  Index of the disposition row
     * @return AssetAcbAuditRowData[]
     */
    public function calculate(AssetAcbAuditRowData $disposition, array $rows, int $index): array
    {
        if (! $disposition->isDisposition()) {
            return [];
        }

        if (! $disposition->isLoss()) {
            return [];
        }

        $dispositionDate = $disposition->event_at;
        $windowStart = $dispositionDate->copy()->subDays(30)->startOfDay();
        $windowEnd = $dispositionDate->copy()->addDays(30)->endOfDay();

        // Track replacement property acquisitions and remaining quantities
        $replacementBuckets = [];

        /** @var AssetAcbAuditRowData $row */
        foreach ($rows as $i => $row) {
            // Skip the disposition itself
            if ($i === $index) {
                continue;
            }

            // Outside the ±30 day window
            if ($row->event_at->lt($windowStart) || $row->event_at->gt($windowEnd)) {
                continue;
            }

            // Acquisition within window → candidate replacement property
            if ($row->isAcquisition()) {
                $replacementBuckets[] = [
                    'row' => $row,
                    'remaining' => $row->quantity_change->abs(),
                    'index' => $i,
                ];
            }
        }

        // No acquisitions → no superficial loss
        if (empty($replacementBuckets)) {
            return [];
        }

        // Walk forward from each acquisition and subtract later dispositions
        foreach ($replacementBuckets as &$bucket) {
            for ($j = $bucket['index'] + 1; $j < count($rows); $j++) {
                $later = $rows[$j];

                if ($later->event_at->gt($windowEnd)) {
                    break;
                }

                if ($later->isDisposition()) {
                    $bucket['remaining'] = $bucket['remaining']
                        ->subtract($later->quantity_change->abs());

                    if ($bucket['remaining']->isZero() || $bucket['remaining']->isNegative()) {
                        break;
                    }
                }
            }
        }

        unset($bucket);

        // Determine if any replacement property remains

        $totalReplacementQuantity = AssetQuantity::zero(
            $disposition->quantity_change->asset_code
        );

        foreach ($replacementBuckets as $bucket) {
            if ($bucket['remaining']->isPositive()) {
                $totalReplacementQuantity = $totalReplacementQuantity
                    ->add($bucket['remaining']);
            }
        }

        if ($totalReplacementQuantity->isZero()) {
            return [];
        }

        $disposedQuantity = $disposition->quantity_change->abs();
        $lossAmount = $disposition->capital_gain_loss->abs();

        $ratio = $totalReplacementQuantity->divide($disposedQuantity);
        $denialRatio = min(1, $ratio->toDecimal());

        $deniedLoss = $lossAmount->multiply($denialRatio);
        $allowableLoss = $lossAmount->subtract($deniedLoss);

        return [
            AssetAcbAuditRowData::superficialLossMarker(
                trigger: $disposition,
                deniedLoss: $deniedLoss,
                allowableLoss: $allowableLoss,
                replacementQuantity: $totalReplacementQuantity,
                disposedQuantity: $disposedQuantity,
            ),
            AssetAcbAuditRowData::superficialLossAcbReinstatement(
                trigger: $disposition,
                deniedLoss: $deniedLoss,
            ),
        ];
    }
}
