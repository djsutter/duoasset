<?php

namespace App\Data\Imports;

class ExodusRowData
{
    public function __construct(
        public readonly string $tx_id,
        public readonly string $tx_url,
        public readonly string $date,
        public readonly string $type,
        public readonly string $coin_amount,
        public readonly ?string $fee,
        public readonly ?string $address,
    ) {}

    public static function fromRow($row): self
    {
        return new self(
            tx_id: $row['TXID'],
            tx_url: $row['TXURL'],
            date: $row['DATE'],
            type: $row['TYPE'],
            coin_amount: $row['COINAMOUNT'],
            fee: $row['FEE'] ?: null,
            address: empty($row['ADDRESS']) ? null : $row['ADDRESS'],
        );
    }
}
