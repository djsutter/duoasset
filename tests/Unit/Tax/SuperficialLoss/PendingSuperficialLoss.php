<?php

namespace Tests\Unit\Tax\SuperficialLoss;

use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;
use App\Types\Money;
use Carbon\CarbonImmutable;

final class PendingSuperficialLoss
{
    // Construction

    public static function rehydrate(
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        Money $originalLoss,
        Money $remainingLoss,
        string $originalUnits,
        string $remainingUnits,
        PendingSuperficialLossStatus $status
    ): self {
        return new self(
            $windowStart,
            $windowEnd,
            $originalLoss,
            $remainingLoss,
            $originalUnits,
            $remainingUnits,
            $status
        );
    }

    // State queries
    public function status(): PendingSuperficialLossStatus
    {
        if ($this->isExpired()) {
            return PendingSuperficialLossStatus::Expired;
        }

        if ($this->remainingLoss->isZero()) {
            return PendingSuperficialLossStatus::FullyDenied;
        }

        if ($this->remainingLoss->lessThan($this->originalLoss)) {
            return PendingSuperficialLossStatus::PartiallyDenied;
        }

        return PendingSuperficialLossStatus::Pending;
    }

    public function isPending(): bool
    {
        return $this->status() === PendingSuperficialLossStatus::Pending;
    }

    public function isClosed(): bool
    {
        return in_array(
            $this->status(),
            [
                PendingSuperficialLossStatus::Expired,
                PendingSuperficialLossStatus::FullyDenied,
            ],
            true
        );
    }
}
