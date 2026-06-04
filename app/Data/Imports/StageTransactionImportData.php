<?php

namespace App\Data\Imports;

use App\Enums\TransactionType;
use App\Models\StageEntry;
use App\Models\StageTransaction;
use Illuminate\Support\Collection;

class StageTransactionImportData
{
    public function __construct(
        public readonly TransactionType $tx_type,
        public readonly \DateTimeInterface $tx_at,
        public readonly string $status,
        public readonly ?string $description = null,
        public readonly ?string $address = null,
        public readonly ?string $source = null,
        /** @var Collection<int, StageEntryImportData> */
        public readonly Collection $entries = new Collection,
    ) {}

    /**
     * Helper to add an entry and return self for chaining.
     */
    public function addEntry(StageEntryImportData $entry): static
    {
        $this->entries->push($entry);

        return $this;
    }

    /**
     * Convert this object into a plain array suitable for persistence.
     */
    public function toArray(): array
    {
        return [
            'tx_type' => $this->tx_type,
            'tx_at' => $this->tx_at,
            'status' => $this->status,
            'description' => $this->description,
            'address' => $this->address,
            'source' => $this->source,
        ];
    }

    public function toModel(): StageTransaction
    {
        return new StageTransaction($this->toArray());
    }

    /**
     * Convert Data entries into StageEntry models, properly linking them to $tx
     *
     * @return Collection<int, StageEntry>
     */
    public function toEntryModels(StageTransaction $tx): Collection
    {
        return $this->entries->map(function (StageEntryImportData $entryDto) use ($tx) {
            $entry = $entryDto->toModel();
            $entry->stage_transaction_id = $tx->id;

            return $entry;
        });
    }
}
