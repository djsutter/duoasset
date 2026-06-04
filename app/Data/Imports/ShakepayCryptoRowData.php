<?php

namespace App\Data\Imports;

class ShakepayCryptoRowData
{
    public function __construct(
        public readonly string $date,
        public readonly ?string $amount_debited,
        public readonly ?string $asset_debited,
        public readonly ?string $amount_credited,
        public readonly ?string $asset_credited,
        public readonly string $market_value,
        public readonly string $market_value_currency,
        public readonly string $book_cost,
        public readonly string $book_cost_currency,
        public readonly string $type,
        public readonly string $spot_rate,
        public readonly ?string $buy_sell_rate,
        public readonly string $description,
    ) {}

    public static function fromRow($row): self
    {
        // @todo do this in the other rowdto s.
        return new self(
            date: $row['Date'],
            amount_debited: $row['Amount Debited'] ?: null,
            asset_debited: $row['Asset Debited'] ?: null,
            amount_credited: $row['Amount Credited'] ?: null,
            asset_credited: $row['Asset Credited'] ?: null,
            market_value: $row['Market Value'],
            market_value_currency: $row['Market Value Currency'],
            book_cost: $row['Book Cost'],
            book_cost_currency: $row['Book Cost Currency'],
            type: $row['Type'],
            spot_rate: $row['Spot Rate'],
            buy_sell_rate: $row['Buy / Sell Rate'] ?: null,
            description: $row['Description'],
        );
    }
}
