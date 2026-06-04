<?php

namespace App\Data\CostBasis;

use App\Types\AssetQuantity;
use App\Types\Money;

final class AcquisitionResultData
{
    public function __construct(
        public readonly AssetQuantity $quantity,    // crypto qty (positive)
        public readonly Money $cost,                // reporting cost (CAD)
        public readonly Money $total_cost,          // cost + fees (CAD)
        public readonly AssetQuantity $new_cum_qty, // cumulative qty after this event (crypto)
        public readonly Money $new_cum_acb,         // cumulative ACB after this event (CAD)
        public readonly Money $acb_per_unit         // ACB per unit after this event (CAD)
    ) {}
}
