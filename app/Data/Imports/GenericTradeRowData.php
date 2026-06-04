<?php

namespace App\Data\Imports;

class GenericTradeRowData
{
    public function __construct(
        public readonly string $date,
        public readonly string $type,
        public readonly string $exchange,
        public readonly string $cost,
        public readonly string $proceeds,
        public readonly ?string $fee,
    ) {}

    public static function fromRow(array $row): self
    {
        [$baseAsset, $altAsset] = explode('-', $row['Exchange']);

        return new self(
            date: $row['Date'],
            type: $row['Type'],
            exchange: $row['Exchange'],
            cost: $row['Cost'],
            proceeds: $row['Proceeds'],
            fee: $row['Fee'] ?? null,
        );
    }
}
