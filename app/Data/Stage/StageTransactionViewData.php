<?php

namespace App\Data\Stage;

use App\Enums\TransactionType;
use App\Models\StageTransaction;
use App\Types\Money;
use Carbon\Carbon;

class StageTransactionViewData
{
    public function __construct(
        public ?int $row,
        public int $id,
        public ?int $match_id,
        public Carbon $tx_at,
        public TransactionType $tx_type,
        public string $status,
        public string $description,
        public ?string $platform1,
        public ?Money $amount1,
        public ?string $platform2,
        public ?Money $amount2,
        public ?string $source,
        public ?string $reference,
    ) {}

    public static function fromModel(StageTransaction $tx, ?int $row = null): self
    {
        $platforms = [];
        $amounts = [];
        foreach ($tx->entries as $entry) {
            if ($entry->atomic_type == 'fee') {
                continue;
            }
            $platforms[] = $entry->wallet?->platform?->name;
            $amounts[] = $entry->effectiveAmount();
        }

        return new self(
            row: $row,
            id: $tx->id,
            match_id: $tx->match_tx_id,
            tx_at: $tx->tx_at,
            tx_type: $tx->tx_type,
            status: $tx->status,
            description: $tx->description,
            platform1: $platforms[0] ?? null,
            amount1: $amounts[0] ?? null,
            platform2: $platforms[1] ?? null,
            amount2: $amounts[1] ?? null,
            source: 'import',
            reference: 'file',
        );
    }
}
