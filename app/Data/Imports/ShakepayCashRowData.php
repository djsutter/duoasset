<?php

namespace App\Data\Imports;

class ShakepayCashRowData
{
    public function __construct(
        public readonly string $date,
        public readonly string $type,
        public readonly string $description,
        public readonly ?string $debit,
        public readonly ?string $credit,
        public readonly string $spot_rate,
        public readonly string $buy_sell_rate,
        public readonly string $balance,
    ) {}

    public static function fromRow($row): self
    {
        return new self(
            date: $row['Date'],
            type: $row['Type'],
            description: $row['Description'],
            debit: $row['Debit'] ?: null,
            credit: $row['Credit'] ?: null,
            spot_rate: $row['Spot Rate'],
            buy_sell_rate: $row['Buy / Sell Rate'],
            balance: $row['Balance'],
        );
    }
}
