<?php

namespace App\Data\Tax\Schedule3;

use App\Types\Money;

final class Schedule3AssetData
{
    public function __construct(
        public int $tax_year,
        public string $asset_code,
        public string $description,
        public Money $proceeds,
        public Money $acb_allocated,
        public Money $acb_reportable,
        public Money $outlays,
        public Money $capital_gain_loss,
        /** @var Schedule3DispositionData[] */
        public array $dispositions,
        public Money $denied_loss_sum = new Money(0, 'CAD'),
    ) {}
}
