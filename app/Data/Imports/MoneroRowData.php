<?php

namespace App\Data\Imports;

class MoneroRowData
{
    public function __construct(
        public readonly string $block_height,
        public readonly string $epoch,
        public readonly string $date,
        public readonly string $direction,
        public readonly string $amount,
        public readonly int $atomic_amount,
        public readonly ?string $fee,
        public readonly string $tx_id,
        public readonly string $label,
    ) {}

    public static function fromRow($row): self
    {
        return new self(
            block_height: $row['blockHeight'],
            epoch: $row['epoch'],
            date: $row['date'],
            direction: $row['direction'],
            amount: (string) round($row['amount'], 8),
            atomic_amount: $row['atomicAmount'],
            fee: $row['fee'] ?: null,
            tx_id: $row['txid'],
            label: $row['label'],
        );
    }
}
