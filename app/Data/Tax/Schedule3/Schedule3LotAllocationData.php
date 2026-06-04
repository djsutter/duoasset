<?php

namespace App\Data\Tax\Schedule3;

use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;

final class Schedule3LotAllocationData
{
    public function __construct(
        // Identity
        public string $lot_id,
        public string $acb_event_id,

        // Acquisition context
        public CarbonImmutable $acquired_at,
        public AssetQuantity $acquired_quantity,
        public Money $acquired_unit_cost,

        // Disposition usage
        public AssetQuantity $disposed_quantity,
        public Money $acb_used_amount,

        // Post-disposition state
        public AssetQuantity $remaining_quantity,
    ) {}
}
