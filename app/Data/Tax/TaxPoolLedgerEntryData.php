<?php

namespace App\Data\Tax;

use App\Enums\AcbEventType;
use App\Enums\TaxPoolLedgerEntryType;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;

final readonly class TaxPoolLedgerEntryData
{
    public function __construct(
        // Identity
        public int $tx_id,
        public string $asset_code,
        public string $currency,
        public TaxPoolLedgerEntryType $event_type,
        public AcbEventType $origin_event_type,
        public string $source_event_id,
        public CarbonImmutable $event_date,

        // Pool deltas
        public AssetQuantity $quantity_delta,
        public AssetQuantity $quantity_after,

        public Money $acb_delta,
        public Money $acb_after,

        // Derived
        public ?Money $unit_cost_after,

        // Disposition-only (nullable)
        public ?Money $proceeds = null,
        public ?Money $acb_allocated = null,
        public ?Money $acb_reportable = null,
        public ?Money $capital_gain_loss_before_denial = null,
        public ?Money $denied_loss = null,
        public ?Money $capital_gain_loss_after_denial = null,
    ) {}
}
