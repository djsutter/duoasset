<?php

namespace App\Actions;

use App\Data\Transactions\BaseTransactionData;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\TransactionService;
use App\Services\WalletBalanceService;
use App\Traits\BuildsEntries;
use Illuminate\Support\Facades\DB;

class CreateTransaction
{
    use BuildsEntries;

    public function __construct(
        protected TransactionService $transactionService,
    ) {}

    public function execute(BaseTransactionData $dto): Transaction
    {
        return DB::transaction(function () use ($dto) {
            $tx = new Transaction([
                'tx_type' => $dto->tx_type,
                'transaction_at' => $dto->transaction_at,
                'description' => $dto->description,
            ]);
            $tx->save();

            $builder = $this->transactionService->resolveBuilder($dto->tx_type);
            $entries = $builder->buildEntriesArray($dto);

            $this->buildEntries($tx, $entries);

            $wbs = app(WalletBalanceService::class);
            foreach ($entries as $entry) {
                $wallet = Wallet::find($entry['wallet_id']);
                $wbs->calculateBalance($wallet, $dto->transaction_at);
            }

            return $tx->fresh('entries');
        });
    }
}
