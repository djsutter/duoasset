<?php

namespace App\Domain\Tax\Pool;

use App\Types\AssetQuantity;
use App\Types\Money;

class PoolDispositionResult
{
    public function __construct(
        public readonly PoolState $newPoolState,
        public readonly Money $acbAllocated,
        public readonly Money $preliminaryGainOrLoss,
        public readonly Money $finalGainOrLoss,
        public readonly Money $deniedLoss,
        public readonly AssetQuantity $unitsDisposed,
    ) {}
}
