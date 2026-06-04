<?php

namespace App\Tax\SuperficialLoss\Domain;

use Carbon\CarbonImmutable;

final class SuperficialLossResolution
{
    public function __construct(
        public readonly PendingSuperficialLoss $loss,
        public readonly SuperficialLossResolutionType $type,
        public readonly CarbonImmutable $resolvedAt
    ) {}
}
