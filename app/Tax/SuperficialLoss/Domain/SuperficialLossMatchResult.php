<?php

namespace App\Tax\SuperficialLoss\Domain;

use App\Types\AssetQuantity;
use App\Types\Money;

final class SuperficialLossMatchResult
{
    public function __construct(
        public readonly AssetQuantity $matchedUnits,
        public readonly Money $deniedLoss
    ) {}

    public function isEmpty(): bool
    {
        return $this->matchedUnits->isZero();
    }

    public static function none(string $assetCode): self
    {
        return new self(
            AssetQuantity::zero($assetCode),
            Money::zero('CAD')
        );
    }
}
