<?php

namespace App\Data\Imports;

class KrakenTradeData
{
    public function __construct(
        public readonly string $txid,
        public readonly string $ordertxid,
        public readonly string $pair,
        public readonly string $aclass,
        public readonly string $subclass,
        public readonly string $time,
        public readonly string $type,
        public readonly string $ordertype,
        public readonly string $price,
        public readonly string $cost,
        public readonly string $fee,
        public readonly string $vol,
        public readonly string $margin,
        public readonly string $misc,
        public readonly string $ledgers,
        public readonly string $posttxid,
    ) {}

    public static function fromRow($row): self
    {
        return new self(
            txid: $row['txid'],
            ordertxid: $row['ordertxid'],
            pair: $row['pair'],
            aclass: $row['aclass'],
            subclass: $row['subclass'],
            time: $row['time'],
            type: $row['type'],
            ordertype: $row['ordertype'],
            price: $row['price'],
            cost: $row['cost'],
            fee: $row['fee'],
            vol: $row['vol'],
            margin: $row['margin'],
            misc: $row['misc'],
            ledgers: $row['ledgers'],
            posttxid: $row['posttxid'],
        );
    }
}
