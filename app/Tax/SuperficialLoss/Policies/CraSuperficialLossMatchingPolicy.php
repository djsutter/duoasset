<?php

namespace App\Tax\SuperficialLoss\Policies;

use App\Tax\Events\AcquisitionEvent;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Domain\SuperficialLossMatchingPolicy;
use App\Tax\SuperficialLoss\Domain\SuperficialLossMatchResult;
use App\Types\AssetQuantity;

final class CraSuperficialLossMatchingPolicy implements SuperficialLossMatchingPolicy
{
    public function match(
        PendingSuperficialLoss $loss,
        AcquisitionEvent $acquisition
    ): SuperficialLossMatchResult {
        // Outside window → no match
        if (
            $acquisition->date->lessThan($loss->windowStart)
            || $acquisition->date->greaterThan($loss->windowEnd)
        ) {
            return SuperficialLossMatchResult::none($loss->assetCode);
        }

        // Different asset → no match
        if ($acquisition->assetCode !== $loss->assetCode) {
            return SuperficialLossMatchResult::none($loss->assetCode);
        }

        // Matchable units
        $matchedUnits = AssetQuantity::min([
            $loss->remainingUnits,
            $acquisition->quantity,
        ]);

        if ($matchedUnits->isZero()) {
            return SuperficialLossMatchResult::none($loss->assetCode);
        }

        // Pro-rata loss denial
        $deniedLoss = $loss->originalLossAmount
            ->multiply(
                $matchedUnits->divide($loss->originalUnits)->toDecimal()
            );

        return new SuperficialLossMatchResult(
            $matchedUnits,
            $deniedLoss
        );
    }
}
