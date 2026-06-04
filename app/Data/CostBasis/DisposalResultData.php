<?php

namespace App\Data\CostBasis;

use App\Types\AssetQuantity;
use App\Types\Money;

final class DisposalResultData
{
    public function __construct(
        public readonly AssetQuantity $quantity,     // crypto qty (negative)
        public readonly Money $proceeds,             // reporting proceeds (CAD, positive)
        public readonly Money $acb_allocated,        // ACB allocated to this disposal (CAD)
        public readonly Money $realized_gain,        // realized gain (CAD) after fees
        public readonly AssetQuantity $new_cum_qty,  // cumulative qty after this event (crypto)
        public readonly Money $new_cum_acb,          // cumulative ACB after this event (CAD)
    ) {}
}
