<?php

namespace App\Data\Tax\Schedule3;

use App\Types\Money;

class Schedule3Data
{
    public function __construct(
        public int $tax_year,
        public string $method, // 'lot' | 'pool'
        public Money $total_proceeds,
        public Money $total_acb_allocated,
        public Money $total_acb_reportable,
        public Money $total_capital_gain_loss,
        public Money $total_denied_loss,
        /** @param  Schedule3AssetData[]  $asset_rows */
        public array $asset_rows = [],
    ) {}
}
