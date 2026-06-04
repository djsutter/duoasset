<?php

namespace App\Domain\Tax\Pool;

use App\Types\AssetQuantity;
use App\Types\Money;

final class PoolState
{
    public function __construct(
        public readonly AssetQuantity $quantity,
        public readonly Money $acb,
    ) {}

    public static function empty(string $assetCode): self
    {
        return new self(
            quantity: AssetQuantity::zero($assetCode),
            acb: Money::zero(getReportingCurrency()),
        );
    }

    public function with(?AssetQuantity $quantity = null, ?Money $acb = null): self
    {
        return new self(
            $quantity ?? $this->quantity,
            $acb ?? $this->acb,
        );
    }
}
