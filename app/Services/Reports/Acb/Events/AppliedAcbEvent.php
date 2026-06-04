<?php

namespace App\Services\Reports\Acb\Events;

use App\Data\Reports\AssetAcbAuditRowData;
use App\Types\AssetQuantity;
use App\Types\Money;

final readonly class AppliedAcbEvent
{
    public function __construct(
        public AssetAcbAuditRowData $row,
        public AssetQuantity $quantity,
        public Money $acb,
    ) {}
}
