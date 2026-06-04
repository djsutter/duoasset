<?php

namespace App\Data\Reports;

use App\Models\AcbEvent;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\Carbon;

final class CapitalGainRowData
{
    public function __construct(
        public readonly Carbon $date,
        public readonly string $asset_code,
        public readonly AssetQuantity $quantity_disposed,
        public readonly Money $proceeds,
        public readonly Money $acb_allocated,
        public readonly Money $expenses,
        public readonly Money $gain_or_loss,
        public readonly bool $is_denied_loss,
        public readonly ?string $tx_id,
        public readonly int $tax_year,
    ) {}

    public static function fromAcbEvent(AcbEvent $event): self
    {
        $gainOrLoss = $event->proceeds->abs()->subtract($event->cost_amount);
        if ($event->fees) {
            $gainOrLoss = $gainOrLoss->subtract($event->fees);
        }

        return new self(
            date: $event->event_at,
            asset_code: $event->asset_code,
            quantity_disposed: $event->quantity,
            proceeds: $event->proceeds,
            acb_allocated: $event->cost_amount,
            expenses: $event->fees ?? Money::zero('CAD'),
            gain_or_loss: $gainOrLoss,
            is_denied_loss: false, // placeholder
            tx_id: $event->tx_id,
            tax_year: $event->event_at->year,
        );
    }

    public function isGain(): bool
    {
        return $this->gain_or_loss->isPositive();
    }

    public function isLoss(): bool
    {
        return $this->gain_or_loss->isNegative();
    }

    public function taxableAmount(): Money
    {
        return $this->gain_or_loss->multiply('0.5');
    }
}
