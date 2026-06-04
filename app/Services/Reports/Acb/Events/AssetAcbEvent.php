<?php

namespace App\Services\Reports\Acb\Events;

use App\Enums\AcbEventType;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\Carbon;

final class AssetAcbEvent
{
    public function __construct(
        public readonly Carbon $event_at,
        public readonly AcbEventType $event_type,
        public readonly int $tx_id,
        public readonly AssetQuantity $quantity,
        public readonly Money $cost_amount,
        public readonly Money $proceeds,
    ) {}
}
