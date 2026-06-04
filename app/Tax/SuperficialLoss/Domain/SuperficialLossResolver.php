<?php

namespace App\Tax\SuperficialLoss\Domain;

use App\Tax\Events\AcquisitionEvent;
use Carbon\CarbonImmutable;

interface SuperficialLossResolver
{
    /**
     * @return list<SuperficialLossResolution>
     */
    public function resolve(
        iterable $pendingLosses,
        CarbonImmutable $today
    ): array;

    public function resolveForAcquisition(
        AcquisitionEvent $acquisition,
        PendingSuperficialLoss $loss
    ): void;
}
