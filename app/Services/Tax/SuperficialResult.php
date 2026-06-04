<?php

namespace App\Services\Tax;

use App\Types\AssetQuantity;
use App\Types\Money;

class SuperficialResult
{
    public function __construct(
        public Money $deniedLoss,
        public Money $allowableLoss,
        public AssetQuantity $deniedUnits,
    ) {}
}
