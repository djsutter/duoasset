<?php

namespace App\Data\Imports;

class TreasureChestRowData
{
    public function __construct(
        public readonly string $confirmed,
        public readonly string $date,
        public readonly string $type,
        public readonly string $label,
        public readonly ?string $address,
        public readonly string $amount,
        public readonly ?string $fee,
        public readonly string $id,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            confirmed: $row['Confirmed'],
            date: $row['Date'],
            type: $row['Type'],
            label: $row['Label'],
            address: $row['Address'] ?: null,
            amount: $row['Amount (ARRR)'],
            fee: $row['Fee'] ?: null,
            id: $row['ID'],
        );
    }
}
