<?php

namespace App\Data\Imports;

class TradeOgreWithdrawalRowData
{
    public function __construct(
        public readonly string $date,
        public readonly string $coin,
        public readonly string $tx_id,
        public readonly string $amount,
        public readonly string $fee,
        public readonly string $address,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            date: $row['Date'],
            coin: $row['Coin'],
            tx_id: $row['TXID'],
            amount: $row['Amount'],
            fee: $row['Fee'],
            address: $row['Address'],
        );
    }
}
