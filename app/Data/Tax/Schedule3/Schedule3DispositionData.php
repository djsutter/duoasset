<?php

namespace App\Data\Tax\Schedule3;

use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;

final readonly class Schedule3DispositionData
{
    private function __construct(
        public int $acb_event_id,
        public CarbonImmutable $date,
        public AssetQuantity $quantity,

        // Schedule 3 authoritative values
        public Money $proceeds,
        public Money $acb_reportable,
        public Money $outlays,
        public Money $capital_gain_loss,

        // Diagnostic / audit support
        public Money $acb_allocated,
        public Money $denied_loss,
        public ?Money $capital_gain_loss_before_denial,
        public string $disposition_type,
        public bool $is_superficial_loss,

        /** @var Schedule3LotAllocationData[] */
        public array $lot_allocations,

        public ?Schedule3SuperficialLossData $superficial_loss,

        /** @var Schedule3AdjustmentData[] */
        public array $adjustments,
    ) {}

    /**
     * Only callable by factory.
     * Keeps constructor private but avoids reflection hacks.
     */
    public static function internalCreate(
        int $acb_event_id,
        CarbonImmutable $date,
        AssetQuantity $quantity,
        Money $proceeds,
        Money $acb_reportable,
        Money $outlays,
        Money $capital_gain_loss,
        Money $acb_allocated,
        Money $denied_loss,
        ?Money $capital_gain_loss_before_denial,
        string $disposition_type,
        bool $is_superficial_loss,
        array $lot_allocations,
        ?Schedule3SuperficialLossData $superficial_loss,
        array $adjustments,
    ): self {
        return new self(
            $acb_event_id,
            $date,
            $quantity,
            $proceeds,
            $acb_reportable,
            $outlays,
            $capital_gain_loss,
            $acb_allocated,
            $denied_loss,
            $capital_gain_loss_before_denial,
            $disposition_type,
            $is_superficial_loss,
            $lot_allocations,
            $superficial_loss,
            $adjustments,
        );
    }
}
