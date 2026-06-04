<?php

namespace App\Tax\Events;

use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;

final class AcquisitionEvent
{
    public function __construct(
        public readonly int $id,
        public readonly string $assetCode,
        public readonly CarbonImmutable $date,
        public readonly AssetQuantity $quantity,
        public readonly Money $costAmount,
    ) {}
}
