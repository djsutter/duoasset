<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Jobs\ValuateImportedTransactions;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Types\Money;
use Throwable;

class ReplayService
{
    public function getTransactionKey(Transaction $transaction): array
    {
        $entry = $transaction->entries()->whereIn('entry_type', ['in', 'out'])->first();

        return [
            'transaction_at' => $transaction->transaction_at,
            'platform' => $entry->wallet->platform->name,
            'wallet' => $entry->wallet->name,
            'foreign_amount' => $entry->foreign_amount->amount,
            'foreign_currency' => $entry->foreign_amount->currency,
        ];
    }

    /**
     * Replay a set of logged joins.
     *
     * @param  array  $log  The replay log loaded from JSON.
     * @return array Summary of results.
     */
    public function replay(array $records): ?array
    {
        $results = [];

        foreach ($records as $record) {
            $results[] = match ($record['action']) {
                'join' => $this->replayJoin($record['data']),
                'update_description' => $this->replayDescriptionChange($record['data']),
                default => null,
            };
        }

        ValuateImportedTransactions::dispatch();

        return $results;
    }

    public function replayDescriptionChange(array $record): ?array
    {
        $numChanged = 0;
        $total = count($record['transactions']);

        foreach ($record['transactions'] as $txKey) {
            if ($tx = $this->findTxByKey($txKey)) {
                $tx->update([
                    'description' => $record['description'],
                ]);
                $numChanged++;
            }
        }

        return [
            'index' => 0,
            'status' => 'ok',
            'message' => "$numChanged of $total description".($total > 1 ? 's' : '').' changed',
        ];
    }

    public function replayJoin(array $record): array
    {
        try {
            $txType = TransactionType::tryFrom($record['tx_type']) ?? null;
            $sourceKey = $record['source'] ?? null;
            $targetKey = $record['target'] ?? null;
            $feeData = $record['fee'] ?? null;
            $description = $record['description'] ?? null;

            // Validate presence of required keys
            if (! $sourceKey || ! $targetKey) {
                return $this->failResult(0, 'Missing source or target key', $record);
            }

            // Find transactions
            $sourceTx = $this->findTxByKey($sourceKey);
            $targetTx = $this->findTxByKey($targetKey);

            if (! $sourceTx) {
                return $this->failResult(0, 'Source transaction not found', $sourceKey);
            }

            if (! $targetTx) {
                return $this->failResult(0, 'Target transaction not found', $targetKey);
            }

            // If the two found transactions are the same record, that's an error
            if ($sourceTx->id === $targetTx->id) {
                return $this->failResult(0, 'Source and target resolved to the same transaction', [
                    'source_id' => $sourceTx->id,
                    'description' => $description,
                ]);
            }

            // Now perform the join using the same algorithm (extracted)
            $newTx = $this->joinTwoTransactions($sourceTx, $targetTx, $description, $txType, $feeData);

            return [
                'index' => 0,
                'status' => 'ok',
                'message' => 'Joined successfully',
                'new_tx_id' => $newTx->id,
                'source_id' => $sourceTx->id,
                'target_id' => $targetTx->id,
            ];
        } catch (Throwable $e) {
            return [
                'index' => 0,
                'status' => 'error',
                'message' => 'Exception: '.$e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * Find a transaction by the deterministic key:
     * - transaction_at (exact match)
     * - platform (name)
     * - wallet (name)
     * - foreign_amount
     * - foreign_currency
     *
     * Returns the Transaction model or null. If more than one candidate is found, returns null.
     */
    protected function findTxByKey(array $key): ?Transaction
    {
        // Validate required fields
        if (
            empty($key['transaction_at']) ||
            empty($key['platform']) ||
            empty($key['wallet']) ||
            empty($key['foreign_amount']) ||
            empty($key['foreign_currency'])
        ) {
            return null;
        }

        $platformName = $key['platform'];
        $wallet = Wallet::where('name', $key['wallet'])
            ->whereHas('platform', function ($q) use ($platformName) {
                $q->where('name', $platformName);
            })
            ->first();

        $foreignAmount = new Money($key['foreign_amount'], $key['foreign_currency']);

        $key['wallet_id'] = $wallet->id;
        $qb = Transaction::query()
            ->where('transaction_at', $key['transaction_at'])
            ->whereHas('entries', function ($q) use ($key, $foreignAmount) {
                $q->where('wallet_id', $key['wallet_id'])
                    ->where('foreign_amount', $foreignAmount->toDecimal());
            });

        $count = $qb->count();

        if ($count === 1) {
            return $qb->with('entries')->first();
        }

        // If zero or multiple matches, return null to indicate an ambiguous match
        return null;
    }

    /**
     * Join two transactions (core logic).
     * This mirrors the original joinSelected() behavior (pick source by transaction_at,
     * filter out external wallets for main in/out entries, preserve fee entry).
     *
     * @param  string|null  $forcedTxType  // optional: 'trade' or 'transfer' to override detection
     * @param  array|null  $feeData  // optional - not required; fee is taken from source entries
     * @return Transaction The newly created joined Transaction
     */
    protected function joinTwoTransactions(Transaction $t1, Transaction $t2, ?string $description = null, ?TransactionType $forcedTxType = null, ?array $feeData = null): Transaction
    {
        // Re-load entries with wallets to be safe
        $t1->load('entries.wallet');
        $t2->load('entries.wallet');

        // Determine source by earliest transaction_at
        $source = $t1->transaction_at <= $t2->transaction_at ? $t1 : $t2;
        $target = $source->id === $t1->id ? $t2 : $t1;

        // Filter out entries that belong to external wallets for selecting in/out
        $sourceInternal = $source->entries->filter(fn ($e) => $e->wallet->type !== 'external');
        $targetInternal = $target->entries->filter(fn ($e) => $e->wallet->type !== 'external');

        // Extract entries
        $srcOut = $sourceInternal->firstWhere('entry_type', 'out');
        $srcIn = $sourceInternal->firstWhere('entry_type', 'in');
        $srcFee = $source->entries->firstWhere('entry_type', 'fee'); // fee may be external

        $tgtOut = $targetInternal->firstWhere('entry_type', 'out');
        $tgtIn = $targetInternal->firstWhere('entry_type', 'in');
        $tgtFee = $target->entries->firstWhere('entry_type', 'fee');

        // Determine which entries to use for the new tx
        $outEntry = $srcOut ?: $tgtOut;
        $inEntry = $srcIn ?: $tgtIn;

        if (! $outEntry || ! $inEntry) {
            throw new \RuntimeException('Insufficient in/out entries to perform join.');
        }

        // Determine transfer vs trade
        $isTransfer = ($outEntry->foreign_currency === $inEntry->foreign_currency);
        if ($forcedTxType === TransactionType::Transfer) {
            $isTransfer = true;
        } elseif ($forcedTxType === TransactionType::Trade) {
            $isTransfer = false;
        }

        $txType = $isTransfer ? TransactionType::Transfer : TransactionType::Trade;

        // Create new transaction
        $newTx = Transaction::create([
            'transaction_at' => $source->transaction_at,
            'tx_type' => $txType,
            'description' => $description ?? ($isTransfer ? 'Joined transfer' : 'Joined trade'),
        ]);

        // Recreate entry rows (create individually to preserve casts)
        $createEntry = function ($entry, $entry_type) use ($newTx) {
            return WalletEntry::create([
                'transaction_id' => $newTx->id,
                'transaction_at' => $newTx->transaction_at,
                'wallet_id' => $entry->wallet_id,
                'entry_type' => $entry_type,
                'amount' => $entry->amount,
                'foreign_amount' => $entry->foreign_amount,
            ]);
        };

        // OUT
        $createEntry($outEntry, 'out');

        // IN
        $createEntry($inEntry, 'in');

        // Fee: prefer source fee, then target fee, then optional feeData fallback
        $feeEntry = $srcFee ?: $tgtFee;

        if ($feeEntry) {
            $createEntry($feeEntry, 'fee');
        } elseif ($feeData && isset($feeData['wallet_id'], $feeData['foreign_amount'])) {
            // If the log included fee details but no fee entry exists in DB, create one from log
            WalletEntry::create([
                'transaction_id' => $newTx->id,
                'transaction_at' => $newTx->transaction_at,
                'wallet_id' => $feeData['wallet_id'],
                'entry_type' => 'fee',
                'amount' => $feeData['amount'] ?? 0,
                'foreign_amount' => $feeData['foreign_amount'],
            ]);
        }

        // Delete originals and their entries
        $t1->entries()->delete();
        $t2->entries()->delete();
        $t1->delete();
        $t2->delete();

        return $newTx;
    }

    protected function failResult($index, $message, $payload = null): array
    {
        return [
            'index' => $index,
            'status' => 'fail',
            'message' => $message,
            'payload' => $payload,
        ];
    }
}
