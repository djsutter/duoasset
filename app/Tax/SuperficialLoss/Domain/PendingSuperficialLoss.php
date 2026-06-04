<?php

namespace App\Tax\SuperficialLoss\Domain;

use App\Tax\SuperficialLoss\Exceptions\ExcessiveLossDenial;
use App\Tax\SuperficialLoss\Exceptions\InvalidSuperficialLossCreation;
use App\Tax\SuperficialLoss\Exceptions\InvalidSuperficialLossTransition;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PendingSuperficialLoss
{
    public ?CarbonImmutable $expiredAt = null;

    private Money $deniedLoss;

    public function __construct(
        public readonly UuidInterface $id,
        public readonly string $assetCode,
        public readonly int $acbEventId,
        public readonly CarbonImmutable $windowStart,
        public readonly CarbonImmutable $windowEnd,
        public readonly Money $originalLossAmount,
        public readonly AssetQuantity $originalUnits,
        public Money $remainingLossAmount,
        public AssetQuantity $remainingUnits,
    ) {
        // Validate windows
        if ($windowStart->greaterThanOrEqualTo($windowEnd)) {
            throw InvalidSuperficialLossCreation::windowInvalid();
        }

        // Validate original amounts
        if ($originalLossAmount->lessThan(Money::zero('CAD'))) {
            throw InvalidSuperficialLossCreation::negativeLoss();
        }

        if ($originalUnits->isZero() || $originalUnits->isNegative()) {
            throw InvalidSuperficialLossCreation::negativeUnits();
        }

        // Validate remaining values
        if ($remainingLossAmount->greaterThan($originalLossAmount) || $remainingUnits->greaterThan($originalUnits)) {
            throw InvalidSuperficialLossCreation::remainingExceedsOriginal();
        }

        $this->deniedLoss = Money::zero($originalLossAmount->currency);
    }

    public static function rehydrate(
        UuidInterface $id,
        string $assetCode,
        int $acbEventId,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        Money $originalLossAmount,
        AssetQuantity $originalUnits,
        Money $remainingLossAmount,
        AssetQuantity $remainingUnits,
    ): self {
        return new self(
            id: $id,
            assetCode: $assetCode,
            acbEventId: $acbEventId,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            originalLossAmount: $originalLossAmount,
            originalUnits: $originalUnits,
            remainingLossAmount: $remainingLossAmount,
            remainingUnits: $remainingUnits,
        );
    }

    public function deny(Money $amount, AssetQuantity $units): void
    {
        if (! $this->status()->canDeny()) {
            throw InvalidSuperficialLossTransition::from($this->status(), 'deny');
        }

        // Cannot deny more than remaining
        if ($amount->greaterThan($this->remainingLossAmount)) {
            throw new ExcessiveLossDenial;
        }

        if ($units->greaterThan($this->remainingUnits)) {
            throw new ExcessiveLossDenial;
        }

        $this->deniedLoss = $this->deniedLoss->add($amount);

        // Apply denial
        $this->remainingLossAmount = $this->remainingLossAmount->subtract($amount);
        $this->remainingUnits = $this->remainingUnits->subtract($units);

        // Terminal: fully denied
        if ($this->remainingUnits->isZero()) {
            return;
        }
    }

    public function deniedLoss(): Money
    {
        return $this->deniedLoss;
    }

    public function expireIfNeeded(CarbonImmutable $asOf): void
    {
        if (! $this->status()->canExpire()) {
            throw InvalidSuperficialLossTransition::from($this->status(), 'expire');
        }

        if ($asOf->lessThanOrEqualTo($this->windowEnd)) {
            return;
        }

        $this->expiredAt = $asOf;
    }

    public function expire(CarbonImmutable $asOf): void
    {
        if ($this->expiredAt !== null) {
            return;
        }

        $this->expiredAt = $asOf;
    }

    public function status(): PendingSuperficialLossStatus
    {
        if ($this->expiredAt !== null) {
            return PendingSuperficialLossStatus::Expired;
        }

        if ($this->remainingUnits->isZero()) {
            return PendingSuperficialLossStatus::FullyDenied;
        }

        if ($this->remainingUnits->lessThan($this->originalUnits)) {
            return PendingSuperficialLossStatus::PartiallyDenied;
        }

        return PendingSuperficialLossStatus::Pending;
    }

    public static function createFromDisposition(
        int $acbEventId,
        string $assetCode,
        Money $superficialLoss,
        AssetQuantity $superficialUnits,
        CarbonImmutable $dispositionDate
    ): self {
        // Guard invariants early
        if ($superficialLoss->isNegative()) {
            throw InvalidSuperficialLossCreation::negativeLoss();
        }

        if ($superficialUnits->isNegative()) {
            throw InvalidSuperficialLossCreation::negativeUnits();
        }

        // Define superficial loss window (CRA-compatible but configurable later)
        $windowStart = $dispositionDate;
        $windowEnd = $dispositionDate->addDays(30);

        // Delegate status derivation to the entity
        return new self(
            id: Uuid::uuid4(),
            assetCode: $assetCode,
            acbEventId: $acbEventId,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            originalLossAmount: $superficialLoss,
            originalUnits: $superficialUnits,
            remainingLossAmount: $superficialLoss,
            remainingUnits: $superficialUnits
        );
    }
}
