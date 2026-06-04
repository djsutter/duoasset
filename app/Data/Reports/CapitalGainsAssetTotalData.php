<?php

namespace App\Data\Reports;

use App\Types\Money;

final class CapitalGainsAssetTotalData
{
    public function __construct(
        public readonly string $asset_code,
        public readonly string $asset_name,
        public readonly Money $total_proceeds,
        public readonly Money $total_acb,
        public readonly Money $total_gain_or_loss,
        public readonly Money $taxable_amount,
    ) {}
}
