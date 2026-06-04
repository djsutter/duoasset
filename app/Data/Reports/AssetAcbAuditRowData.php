<?php

namespace App\Data\Reports;

use App\Enums\AcbAuditAdjustmentReason;
use App\Enums\AcbEventType;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\Carbon;

final class AssetAcbAuditRowData
{
    public function __construct(
        // Core identity
        public readonly Carbon $event_at,
        public readonly AcbEventType $event_type,
        public readonly string $tx_id,

        // Quantity tracking
        public readonly AssetQuantity $quantity_change,
        public readonly AssetQuantity $quantity_after,

        // ACB tracking
        public readonly Money $acb_change,
        public readonly Money $acb_after,

        // Explicit unit cost (tax basis)
        // - acquisition: acb_change / units acquired
        // - disposal: acb_allocated / units disposed
        // - adjustment: acb_change / affected units
        // A null value means "not applicable"
        public readonly ?Money $unit_cost = null,

        // Disposition-only (null otherwise)
        public readonly ?Money $proceeds = null,
        public readonly ?Money $acb_allocated = null,
        public readonly ?Money $capital_gain_loss = null,

        // Adjustment-only metadata (null otherwise)
        public readonly ?AcbAuditAdjustmentReason $adjustment_reason = null,
        public readonly ?string $annotates_base_key = null,
        public readonly ?array $source_tx_ids = null,   // e.g. loss dispositions feeding this adjustment
        public readonly ?AssetQuantity $adjusted_units = null,

        public readonly ?array $meta = null,
    ) {}

    public function isDisposition(): bool
    {
        return $this->event_type === AcbEventType::Disposal;
    }

    public function isAcquisition(): bool
    {
        return $this->event_type === AcbEventType::Acquisition;
    }

    public function isLoss(): bool
    {
        return $this->capital_gain_loss->isNegative();
    }

    public function rowKey(): string
    {
        return $this->tx_id
            .'-'.$this->event_type->value
            .'-'.$this->event_at->timestamp
            .'-'.($this->adjustment_reason->value ?? 'base');
    }

    // Temporary? Not sure.
    public static function superficialLossMarker(
        self $trigger,
        Money $deniedLoss,
        Money $allowableLoss,
        AssetQuantity $replacementQuantity,
        AssetQuantity $disposedQuantity,
    ): self {
        return new self(
            event_at: $trigger->event_at,
            event_type: AcbEventType::Adjustment,
            tx_id: $trigger->tx_id,

            quantity_change: AssetQuantity::zero($trigger->quantity_after->asset_code),
            quantity_after: $trigger->quantity_after,

            acb_change: Money::zero($trigger->acb_after->currency),
            acb_after: $trigger->acb_after,

            unit_cost: null,
            proceeds: Money::zero($trigger->acb_after->currency),
            acb_allocated: Money::zero($trigger->acb_after->currency),
            capital_gain_loss: Money::zero($trigger->acb_after->currency),

            adjustment_reason: AcbAuditAdjustmentReason::SuperficialLossMarker,
            annotates_base_key: $trigger->rowKey(),

            meta: [
                'superficial_loss' => [
                    'denied_loss' => $deniedLoss->toDecimal(),
                    'allowable_loss' => $allowableLoss->toDecimal(),
                    'replacement_quantity' => $replacementQuantity->toDecimal(),
                    'disposed_quantity' => $disposedQuantity->toDecimal(),
                ],
            ],
        );
    }

    public static function superficialLossAcbReinstatement(
        self $trigger,
        Money $deniedLoss
    ): self {
        $effectiveDate = $trigger->event_at
            ->copy()
            ->addDays(30)
            ->endOfDay();

        return new self(
            event_at: $effectiveDate,
            event_type: AcbEventType::Adjustment,
            tx_id: $trigger->tx_id,

            quantity_change: AssetQuantity::zero($trigger->quantity_after->asset_code),
            quantity_after: $trigger->quantity_after,

            acb_change: $deniedLoss,
            acb_after: $trigger->acb_after->add($deniedLoss),

            unit_cost: null,
            proceeds: Money::zero($trigger->acb_after->currency),
            acb_allocated: Money::zero($trigger->acb_after->currency),
            capital_gain_loss: Money::zero($trigger->acb_after->currency),

            adjustment_reason: AcbAuditAdjustmentReason::SuperficialLossReinstatement,

            meta: [
                'superficial_loss_acb_reinstatement' => true,
                'trigger_tx_id' => $trigger->tx_id,
            ],
        );
    }
}
