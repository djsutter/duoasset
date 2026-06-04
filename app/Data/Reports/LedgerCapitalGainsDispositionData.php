<?php

namespace App\Data\Reports;

use App\Models\Asset;
use App\Types\AssetQuantity;
use App\Types\Money;

final class LedgerCapitalGainsDispositionData
{
    public function __construct(
        public readonly string $asset_code,
        public readonly string $transaction_id,
        public readonly string $disposed_at,
        public readonly int $tax_year,

        public readonly AssetQuantity $quantity,
        public readonly Money $proceeds,
        public readonly Money $acb_allocated,
        public readonly Money $expenses,
        public readonly Money $gain_or_loss,
    ) {}

    public static function fromLedgerRow(
        CapitalGainRowData $row,
        Asset $asset
    ): self {
        return new self(
            asset_code: $asset->asset_code,
            transaction_id: (string) ($row->tx_id ?? ''),
            disposed_at: $row->date->toDateString(),
            tax_year: $row->tax_year,

            quantity: $row->quantity_disposed,
            proceeds: $row->proceeds,
            acb_allocated: $row->acb_allocated,
            expenses: $row->expenses,
            gain_or_loss: $row->gain_or_loss,
        );
    }
}
