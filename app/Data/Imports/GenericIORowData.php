<?php

namespace App\Data\Imports;

class GenericIORowData
{
    public function __construct(
        public readonly string $date,
        public readonly string $direction,
        public readonly string $asset,
        public readonly string $amount,
        public readonly ?string $fee,
        public readonly ?string $address,
        public readonly ?string $description,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            date: $row['Date'],
            direction: $row['Direction'],
            asset: $row['Asset'],
            amount: $row['Amount'],
            fee: $row['Fee'] ?? null,
            address: $row['Address'] ?? null,
            description: $row['Description'] ?? null,
        );
    }
}
