<?php

namespace App\Actions;

use App\Data\Transactions\BaseTransactionData;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\TransactionService;
use App\Services\WalletBalanceService;
use App\Traits\BuildsEntries;
use Illuminate\Support\Facades\DB;

class UpdateTransaction
{
    use BuildsEntries;

    public function __construct(
        protected TransactionService $transactionService,
    ) {}

    public function execute(BaseTransactionData $dto): Transaction
    {
        return DB::transaction(function () use ($dto) {
            $tx = Transaction::with('entries')->findOrFail($dto->id);

            $this->updateHeader($tx, $dto);

            $builder = $this->transactionService->resolveBuilder($dto->tx_type);
            $entries = $builder->buildEntriesArray($dto);

            if ($this->entriesHaveChanged($tx, $entries)) {
                $this->replaceEntries($tx, $entries);
            }

            $this->updateMetadata($tx, $dto);

            $wbs = app(WalletBalanceService::class);
            foreach ($entries as $entry) {
                $wallet = Wallet::find($entry['wallet_id']);
                $wbs->calculateBalance($wallet, $dto->transaction_at);
            }

            return $tx->fresh('entries');
        });
    }

    protected function updateHeader(Transaction $tx, BaseTransactionData $dto): void
    {
        $tx->fill([
            'transaction_at' => $dto->transaction_at,
            'tx_type' => $dto->tx_type,
            'description' => $dto->description,
        ])->save();
    }

    protected function updateMetadata(Transaction $tx, BaseTransactionData $dto): void
    {
        if (property_exists($dto, 'address') && $dto->address !== null) {
            $tx->update(['address' => $dto->address]);
        }
    }

    protected function entriesHaveChanged(Transaction $tx, array $expected): bool
    {
        $existing = $tx->entries->map(fn ($e) => [
            'entry_type' => $e->entry_type,
            'wallet_id' => $e->wallet_id,
            'foreign_amount' => $e->foreign_amount?->amount,
        ])->toArray();

        return $existing !== $expected;
    }
}
