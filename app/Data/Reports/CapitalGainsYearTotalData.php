<?php

namespace App\Data\Reports;

use App\Types\Money;

final class CapitalGainsYearTotalData
{
    public function __construct(
        public readonly int $tax_year,
        public readonly Money $total_proceeds,
        public readonly Money $total_acb,
        public readonly Money $total_gain_or_loss,
        public readonly Money $taxable_amount,
    ) {}
}
