<?php

namespace App\Data\Imports;

class TradeOgreDepositRowData
{
    public function __construct(
        public readonly string $date,
        public readonly string $coin,
        public readonly string $tx_d,
        public readonly string $amount,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            date: $row['Date'],
            coin: $row['Coin'],
            tx_d: $row['TXID'],
            amount: $row['Amount'],
        );
    }
}
