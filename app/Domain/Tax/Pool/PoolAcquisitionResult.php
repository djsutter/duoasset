<?php

namespace App\Domain\Tax\Pool;

use App\Types\AssetQuantity;
use App\Types\Money;

final class PoolAcquisitionResult
{
    public function __construct(
        public PoolState $newPoolState,
        public Money $costAdded,
        public AssetQuantity $unitsAdded,
    ) {}
}
