<?php

namespace App\Services\Tax;

use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\PooledSuperficialAllocation;
use App\Types\AssetQuantity;
use App\Types\Money;

final class SuperficialLossEvaluator
{
    public function evaluate(
        AcbEvent $disposition,
        AssetQuantity $unitsDisposed,
        Money $capitalLossBeforeDenial,
        AssetQuantity $unitsRemainingAfterDisposition
    ): SuperficialResult {
        $windowStart = $disposition->event_at->copy()->subDays(30);
        $windowEnd = $disposition->event_at->copy()->addDays(30);

        // Total replacement acquisitions in window
        $replacementUnits = AcbEvent::query()
            ->where('asset_code', $disposition->asset_code)
            ->where('event_type', AcbEventType::Acquisition)
            ->whereBetween('event_at', [$windowStart, $windowEnd])
            ->get()
            ->reduce(
                fn (AssetQuantity $carry, AcbEvent $event) => $carry->add($event->quantity),
                AssetQuantity::zero($disposition->asset_code)
            );

        // Already allocated units (avoid double denial)
        $alreadyAllocated = PooledSuperficialAllocation::query()
            ->where('asset_code', $disposition->asset_code)
            ->where(function ($q) use ($windowStart, $windowEnd) {
                $q->whereBetween('window_start', [$windowStart, $windowEnd])
                    ->orWhereBetween('window_end', [$windowStart, $windowEnd]);
            })
            ->get()
            ->reduce(
                fn (AssetQuantity $carry, PooledSuperficialAllocation $allocation) => $carry->add($allocation->allocated_units),
                AssetQuantity::zero($disposition->asset_code)
            );

        $availableReplacementUnits = $replacementUnits->subtract($alreadyAllocated);

        // CRA superficial loss unit rule
        $deniedUnits = AssetQuantity::min([
            $unitsDisposed,
            $availableReplacementUnits,
            $unitsRemainingAfterDisposition,
        ]);

        if ($deniedUnits->isZero()) {
            return new SuperficialResult(
                deniedLoss: Money::zero(getReportingCurrency()),
                allowableLoss: $capitalLossBeforeDenial->negate(),
                deniedUnits: $deniedUnits,
            );
        }

        // Compute proportional denied loss
        $ratio = $deniedUnits->divide($unitsDisposed);

        $deniedLoss = $capitalLossBeforeDenial
            ->multiplyByQuantity($ratio);

        $allowableLoss = $capitalLossBeforeDenial
            ->subtract($deniedLoss)
            ->negate(); // return as negative number

        return new SuperficialResult(
            deniedLoss: $deniedLoss,
            allowableLoss: $allowableLoss,
            deniedUnits: $deniedUnits,
        );
    }
}
