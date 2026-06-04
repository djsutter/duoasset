<?php

namespace App\Data\Reports;

use App\Models\Asset;
use App\Types\AssetQuantity;
use App\Types\Money;

final class CraCapitalGainsDispositionData
{
    public function __construct(
        // Identification
        public readonly string $asset_code,
        public readonly string $asset_description,
        public readonly string $transaction_id,
        public readonly string $disposed_at, // YYYY-MM-DD
        public readonly int $tax_year,

        // Quantity & unit pricing (crypto transparency)
        public readonly AssetQuantity $quantity_disposed,
        public readonly Money $proceeds_per_unit,
        public readonly Money $acb_per_unit,

        // CRA financials
        public readonly Money $proceeds_of_disposition,
        public readonly Money $adjusted_cost_base,
        public readonly Money $outlays_and_expenses,
        public readonly Money $capital_gain_loss,

        // Compliance / diagnostics
        public readonly Money $superficial_loss_denied,
        public readonly Money $net_capital_gain_loss,
        public readonly Money $running_acb_after,
    ) {}

    public static function fromCraNormalized(
        array $row,
        Asset $asset
    ): self {
        $quantity = $row['quantity'];

        // Unit prices are derived for transparency only; not CRA-required
        $proceedsPerUnit = $quantity->isZero()
            ? Money::zero($row['proceeds']->currency)
            : $row['proceeds']->divide($quantity->toDecimal());

        $acbPerUnit = $quantity->isZero()
            ? Money::zero($row['acb']->currency)
            : $row['acb']->divide($quantity->toDecimal());

        return new self(
            // Identification
            asset_code: $asset->asset_code,
            asset_description: $asset->name ?? $asset->asset_code,
            transaction_id: (string) ($row['tx_id'] ?? ''),
            disposed_at: $row['disposed_at']->toDateString(),
            tax_year: $row['tax_year'],

            // Quantity & unit pricing
            quantity_disposed: $quantity,
            proceeds_per_unit: $proceedsPerUnit,
            acb_per_unit: $acbPerUnit,

            // CRA financials (all normalized)
            proceeds_of_disposition: $row['proceeds'],
            adjusted_cost_base: $row['acb'],
            outlays_and_expenses: $row['expenses'],
            capital_gain_loss: $row['gain_or_loss'],

            // Compliance / diagnostics (explicit placeholders)
            superficial_loss_denied: Money::zero($row['gain_or_loss']->currency),
            net_capital_gain_loss: $row['gain_or_loss'],
            running_acb_after: Money::zero($row['gain_or_loss']->currency),
        );
    }
}
