<?php

namespace App\Data\Imports;

class KrakenLedgerData
{
    public function __construct(
        public readonly string $txid,
        public readonly string $refid,
        public readonly string $time,
        public readonly string $type,
        public readonly ?string $subtype,
        public readonly string $aclass,
        public readonly string $subclass,
        public readonly string $asset,
        public readonly string $wallet,
        public readonly string $amount,
        public readonly string $fee,
        public readonly string $balance,
    ) {}

    public static function fromRow($row): self
    {
        return new self(
            txid: $row['txid'],
            refid: $row['refid'],
            time: $row['time'],
            type: $row['type'],
            subtype: $row['subtype'] ?: null,
            aclass: $row['aclass'],
            subclass: $row['subclass'],
            asset: $row['asset'],
            wallet: $row['wallet'],
            amount: $row['amount'],
            fee: $row['fee'],
            balance: $row['balance'],
        );
    }
}
