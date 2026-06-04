<?php

namespace App\Tax\SuperficialLoss\Domain;

use App\Tax\Events\AcquisitionEvent;
use Carbon\CarbonImmutable;

final class CraSuperficialLossResolver implements SuperficialLossResolver
{
    public function __construct(
        private SuperficialLossMatchingPolicy $policy
    ) {}

    public function resolve(
        iterable $pendingLosses,
        CarbonImmutable $today
    ): array {
        $resolutions = [];

        foreach ($pendingLosses as $loss) {
            if ($loss->status() !== PendingSuperficialLossStatus::Pending) {
                continue;
            }

            if ($today->greaterThan($loss->windowEnd)) {
                $resolutions[] = new SuperficialLossResolution(
                    loss: $loss,
                    type: SuperficialLossResolutionType::Expired,
                    resolvedAt: $today
                );
            } else {
                $resolutions[] = new SuperficialLossResolution(
                    loss: $loss,
                    type: SuperficialLossResolutionType::StillPending,
                    resolvedAt: $today
                );
            }
        }

        return $resolutions;
    }

    public function resolveForAcquisition(AcquisitionEvent $acquisition, PendingSuperficialLoss $loss): void
    {
        if ($loss->status() !== PendingSuperficialLossStatus::Pending) {
            return;
        }

        $result = $this->policy->match($loss, $acquisition);

        if ($result->matchedUnits->isZero()) {
            return;
        }

        $loss->deny(
            $result->deniedLoss,
            $result->matchedUnits
        );
    }
}
