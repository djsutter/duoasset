<?php

namespace App\Domain\Tax\Continuity;

use App\Types\AssetQuantity;
use App\Types\Money;

final class AssetStateSnapshot
{
    public function __construct(
        public readonly AssetQuantity $quantity,
        public readonly Money $acb,
    ) {}
}
