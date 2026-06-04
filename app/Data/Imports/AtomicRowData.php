<?php

namespace App\Data\Imports;

class AtomicRowData
{
    public function __construct(
        public readonly string $tx_id,
        public readonly string $tx_url,
        public readonly string $tx_type,
        public readonly string $coin_amount,
        public readonly string $asset,
        public readonly string $date,
        public readonly ?string $fee,
        public readonly ?string $address,
        public readonly ?string $description,
    ) {}

    public static function fromRow($row): self
    {
        return new self(
            tx_id: $row['TX ID'],
            tx_url: $row['TX URL'],
            tx_type: $row['Tx type'],
            coin_amount: $row['Coin Amount'],
            asset: $row['Asset'],
            date: $row['Date'],
            fee: $row['Fee'] ?: null,
            address: $row['Address'] ?: null,
            description: $row['Description'] ?: null,
        );
    }
}
