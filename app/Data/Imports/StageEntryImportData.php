<?php

namespace App\Data\Imports;

use App\Models\StageEntry;
use App\Types\Money;

class StageEntryImportData
{
    public function __construct(
        public readonly \DateTimeInterface $tx_at,
        public readonly string $atomic_type,
        public readonly ?int $wallet_id,
        public readonly ?Money $amount = null,
        public readonly ?Money $foreign_amount = null,
    ) {}

    public function toArray(): array
    {
        return [
            'tx_at' => $this->tx_at,
            'atomic_type' => $this->atomic_type,
            'wallet_id' => $this->wallet_id,
            'amount' => $this->amount,
            'foreign_amount' => $this->foreign_amount,
        ];
    }

    public function toModel(): StageEntry
    {
        return new StageEntry($this->toArray());
    }
}
