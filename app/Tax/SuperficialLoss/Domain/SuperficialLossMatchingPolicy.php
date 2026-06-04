<?php

namespace App\Tax\SuperficialLoss\Domain;

use App\Tax\Events\AcquisitionEvent;

interface SuperficialLossMatchingPolicy
{
    public function match(
        PendingSuperficialLoss $loss,
        AcquisitionEvent $acquisition
    ): SuperficialLossMatchResult;
}
