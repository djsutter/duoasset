<?php

namespace App\Data\Reports;

use App\Types\Money;

final class CraCapitalGainsAssetTotalData
{
    public function __construct(
        public readonly string $asset_code,
        public readonly string $asset_name,
        public readonly Money $proceeds_of_disposition,
        public readonly Money $adjusted_cost_base,
        public readonly Money $outlays_and_expenses,
        public readonly Money $capital_gain_loss,
        public readonly Money $taxable_capital_gain,
    ) {}
}
