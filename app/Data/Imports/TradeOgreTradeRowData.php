<?php

namespace App\Data\Imports;

class TradeOgreTradeRowData
{
    public function __construct(
        public readonly string $type,
        public readonly string $exchange,
        public readonly string $date,
        public readonly string $amount,
        public readonly string $price,
        public readonly string $fee,
    ) {}

    public static function fromRow(array $row): self
    {
        [$baseAsset, $altAsset] = explode('-', $row['Exchange']);

        return new self(
            type: $row['Type'],
            exchange: $row['Exchange'],
            date: $row['Date'],
            amount: $row['Amount'],
            price: $row['Price'],
            fee: $row['Fee'],
        );
    }
}
