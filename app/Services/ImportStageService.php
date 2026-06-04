<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Jobs\ValuateImportedTransactions;
use App\Models\Platform;
use App\Models\StageEntry;
use App\Models\StageTransaction;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Types\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ImportStageService
{
    public function __construct(
        protected WalletService $walletService,
    ) {}

    public function autoMatch(): int
    {
        $numAutoMatched = 0;

        $numMatch = $this->matchByAmountAndDate(StageTransaction::where('status', StageTransaction::STATUS_UNMATCHED)
            ->with(['entries.wallet.platform'])
            ->orderBy('tx_at')
            ->get());

        $numAutoMatched += $numMatch;

        $numMatch = $this->matchByInternalWallet(StageTransaction::where('status', StageTransaction::STATUS_UNMATCHED)
            ->with(['entries.wallet.platform'])
            ->orderBy('tx_at')
            ->get());

        $numAutoMatched += $numMatch;

        $this->customMatching();

        return $numAutoMatched;
    }

    private function customMatching(): void
    {
        StageTransaction::where('tx_at', '2020-12-11 11:20:54')->update(['description' => 'Buy 1oz gold from SilverGoldBull']);
        StageTransaction::where('description', 'like', 'Interac%')->update(['status' => 'external']);

        // $this->customTransfer('TradeOgre', 'Treasure Chest', 'ARRR');
        // $this->customTransfer('TradeOgre', 'Avian', 'AVN');
        // $this->customTransfer('TradeOgre', 'Exodus', 'XMR', '2021-04-29', '2021-12-07');
    }

    /**
     * Create transfers from platform1 to platform2 for a currency.
     */
    private function customTransfer($platformName1, $platformName2, $currency, ?string $fromDate = null, ?string $toDate = null): void
    {
        $platform1 = Platform::where('name', $platformName1)->firstOrFail();
        $platform2 = Platform::where('name', $platformName2)->firstOrFail();
        $wallet1 = Wallet::where('platform_id', $platform1->id)->where('currency', $currency)->firstOrFail();
        $wallet2 = Wallet::where('platform_id', $platform2->id)->where('currency', $currency)->firstOrFail();

        $query = StageEntry::where('wallet_id', $wallet1->id)
            ->with('stageTransaction')
            ->whereHas('stageTransaction', function ($q) {
                $q->where('status', StageTransaction::STATUS_UNMATCHED);
            });
        if ($fromDate) {
            $query->whereDate('tx_at', '>=', $fromDate);
        }
        if ($toDate) {
            $toDate = Carbon::parse($toDate)->endOfDay();
            $query->whereDate('tx_at', '<=', $toDate);
        }

        foreach ($query->get() as $entry) {
            if (! in_array($entry->atomic_type, ['in', 'out'])) {
                continue;
            }
            DB::transaction(function () use ($entry, $platformName1, $platformName2, $wallet2, $currency) {
                $tx = $entry->stageTransaction;
                if ($entry->atomic_type == 'in') {
                    $description = "Transfer $currency from $platformName2 to $platformName1";
                    $atomicType = 'out';
                    $newTxType = TransactionType::Send;
                } elseif ($entry->atomic_type == 'out') {
                    $description = "Transfer $currency from $platformName1 to $platformName2";
                    $atomicType = 'in';
                    $newTxType = TransactionType::Receive;
                }
                $newTx = StageTransaction::create([
                    'num' => $this->nextTxNumber(),
                    'tx_at' => $tx->tx_at,
                    'tx_type' => $newTxType,
                    'status' => 'automatched',
                    'match_tx_id' => $tx->id,
                    'description' => $description,
                ]);
                StageEntry::create([
                    'stage_transaction_id' => $newTx->id,
                    'tx_at' => $newTx->tx_at,
                    'atomic_type' => $atomicType,
                    'wallet_id' => $wallet2->id,
                    'amount' => $entry->amount?->negated(),
                    'foreign_amount' => $entry->foreign_amount?->negated(),
                ]);
                $tx->update([
                    'status' => StageTransaction::STATUS_AUTOMATCHED,
                    'match_tx_id' => $newTx->id,
                    'description' => $description,
                ]);
            });
        }
    }

    public function export(): void
    {
        StageTransaction::truncate();
        StageEntry::truncate();

        $query = Transaction::orderBy('transaction_at');

        $query->chunk(500, function ($transactions) {
            foreach ($transactions as $tx) {
                $this->exportTransaction($tx);
            }
        });
    }

    protected function exportTransaction(Transaction $tx): void
    {
        $stageTx = StageTransaction::create([
            'num' => $this->nextTxNumber(),
            'tx_at' => $tx->transaction_at,
            'tx_type' => $tx->tx_type,
            'description' => $tx->description,
            'address' => $tx->address,
        ]);

        $count = 0;
        foreach ($tx->entries as $entry) {
            // Ignore entries to external wallets, which have no wallets.
            if ($entry->wallet) {
                $count++;
                StageEntry::create([
                    'stage_transaction_id' => $stageTx->id,
                    'tx_at' => $stageTx->tx_at,
                    'atomic_type' => $entry->entry_type,
                    'wallet_id' => $entry->wallet?->id,
                    'amount' => $entry->amount,
                    'foreign_amount' => $entry->foreign_amount,
                ]);
            }
        }

        $stageTx->status = $count == 2 ? 'matched' : 'unmatched';
        $stageTx->save();
    }

    /**
     * Get a list of unmatched StageEntries which match the given amount and are ±12 hours.
     */
    public function findMatchingEntries(
        Carbon $date,
        ?Money $amount = null,
        ?Wallet $wallet = null,
        ?string $atomicType = null,
        ?int $notMatchingWalletId = null,
        float $tolerancePercent = 0
    ): array {
        /*
         * Build query for entries within ±24 hours
         */
        $query = StageEntry::query()
            ->whereBetween('tx_at', [
                $date->copy()->subHours(12),
                $date->copy()->addHours(12),
            ])
            ->whereHas('stageTransaction', function ($q) {
                $q->whereNull('match_tx_id');
            });

        if ($notMatchingWalletId) {
            $query->where('wallet_id', '!=', $notMatchingWalletId);
        }

        if ($wallet) {
            $query->where('wallet_id', $wallet->id);
        }

        if ($atomicType) {
            $query->where('atomic_type', $atomicType);
        }

        $entries = $query->with('stageTransaction')->get();

        if ($amount) {
            $entries = $entries->filter(function ($entry) use ($amount, $tolerancePercent) {
                $entryMoney = $entry->effectiveAmount();

                if (! $entryMoney || $entryMoney->currency !== $amount->currency) {
                    return false;
                }

                // Relative tolerance check using absolute values
                $diff = abs($entryMoney->amount - $amount->amount);
                $allowedDelta = abs($amount->amount) * ($tolerancePercent / 100);

                return $diff <= $allowedDelta;
            });
        }

        // Sort by smallest time delta
        return $entries
            ->sortBy(fn ($entry) => abs($entry->tx_at->diffInSeconds($date)))
            ->values()
            ->all();
    }

    public function import(): void
    {
        Transaction::truncate();
        WalletEntry::truncate();

        $stageTransactions = StageTransaction::where('status', '!=', StageTransaction::STATUS_CONFIRMED)
            ->with(['entries', 'matchedTransaction.entries'])
            ->orderBy('tx_at')
            ->get();

        foreach ($stageTransactions as $stageTx) {
            if ($stageTx->status === StageTransaction::STATUS_IGNORED) {
                continue;
            }

            if ($stageTx->match_tx_id) {
                // Handle matched pairs (only process the primary one)
                if ($stageTx->match_tx_id < $stageTx->id) {
                    continue; // already processed from the other side
                }
                $this->importMatchedPair($stageTx, $stageTx->matchedTransaction);
            } else {
                $this->importSingle($stageTx);
            }
        }

        ValuateImportedTransactions::dispatch();
    }

    protected function importMatchedPair(StageTransaction $a, StageTransaction $b): void
    {
        DB::transaction(function () use ($a, $b) {
            // Collect entries from both sides
            $allEntries = collect([$a, $b])->flatMap(fn ($tx) => $tx->entriesInOrOut);

            // Pick one 'in' and one 'out' entry if possible
            /** @var StageEntry $inEntry
             * @var StageEntry $outEntry
             */
            $inEntry = $allEntries->first(fn ($e) => $e->effectiveAmount()->isPositive());
            $outEntry = $allEntries->first(fn ($e) => $e->effectiveAmount()->isNegative());

            // Fallback: if both are positive/negative, take first two
            $entries = collect([$inEntry, $outEntry])->filter();

            if ($allEntries->count() > 2) {
                logger()->warning('Matched pair had extra entries', [
                    'a_id' => $a->id,
                    'b_id' => $b->id,
                    'count' => $allEntries->count(),
                ]);
            }

            if ($entries->count() < 2 && $allEntries->count() >= 2) {
                $entries = $allEntries->take(2);
            }

            // Detect a fee entry if any
            $feeEntry = collect([$a, $b])
                ->flatMap(fn ($tx) => $tx->entries)
                ->firstWhere('atomic_type', 'fee');

            $txType = $a->tx_type ?? TransactionType::Transfer;
            $description = $a->description ?: $b->description;

            // Only override for send+receive pairs
            $isSendReceivePair = in_array($a->tx_type, [TransactionType::Send, TransactionType::Receive], true)
                && in_array($b->tx_type, [TransactionType::Send, TransactionType::Receive], true);

            if ($isSendReceivePair && $inEntry && $outEntry) {
                $fromWallet = $outEntry->wallet;
                $toWallet = $inEntry->wallet;

                if ($fromWallet && $toWallet) {
                    // Internal transfer
                    $currency = $inEntry->effectiveCurrency();
                    if ($fromWallet->platform->id == $toWallet->platform->id) {
                        $source = $fromWallet->name;
                        $dest = $toWallet->name;
                        $description = "Transfer from {$source} to {$dest}";
                    } else {
                        $source = $fromWallet->platform->name;
                        $dest = $toWallet->platform->name;
                        $description = "Transfer {$currency} from {$source} to {$dest}";
                    }
                    $txType = TransactionType::Transfer;
                }
            }

            // Create the finalized transaction
            $transaction = Transaction::create([
                'transaction_at' => $a->tx_at,
                'tx_type' => $txType,
                'description' => $description,
                'address' => $a->address ?: $b->address,
            ]);

            // Import both sides
            foreach ($entries as $entry) {
                $wallet = $entry->wallet
                    ? $entry->wallet
                    : $this->walletService->getOrCreateExternalWallet($entry->foreign_currency ?? $entry->currency);

                WalletEntry::create([
                    'transaction_at' => $transaction->transaction_at,
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $wallet?->id,
                    'entry_type' => $entry->atomic_type,
                    'amount' => $entry->amount,
                    'currency' => $entry->currency,
                    'foreign_amount' => $entry->foreign_amount,
                    'foreign_currency' => $entry->foreign_currency,
                ]);
            }

            if ($feeEntry && ! $feeEntry->effectiveAmount()->isZero()) {
                $wallet = $feeEntry->wallet
                    ? $feeEntry->wallet
                    : $this->walletService->getOrCreateExternalWallet(
                        $feeEntry->foreign_currency ?? $feeEntry->currency
                    );
                WalletEntry::create([
                    'transaction_id' => $transaction->id,
                    'transaction_at' => $transaction->transaction_at,
                    'wallet_id' => $wallet?->id,
                    'entry_type' => 'fee',
                    'amount' => $feeEntry->amount,
                    'currency' => $feeEntry->currency,
                    'foreign_amount' => $feeEntry->foreign_amount,
                    'foreign_currency' => $feeEntry->foreign_currency,
                ]);
            }

            // Mark both as processed
            $a->update(['status' => StageTransaction::STATUS_CONFIRMED]);
            $b->update(['status' => StageTransaction::STATUS_CONFIRMED]);
        });
    }

    protected function importSingle(StageTransaction $stageTx): void
    {
        DB::transaction(function () use ($stageTx) {
            $entries = $stageTx->entriesInOrOut;
            $feeEntry = $stageTx->entries->firstWhere('atomic_type', 'fee');

            // If unmatched, synthesize a balancing entry only when needed
            if (in_array($stageTx->status, [StageTransaction::STATUS_UNMATCHED, StageTransaction::STATUS_EXTERNAL])) {
                if ($entries->count() === 1) {
                    /** @var StageEntry $original */
                    $original = $entries->first();

                    // Clone and invert the entry
                    $complement = $original->replicate();
                    $complement->wallet_id = null; // external wallet only
                    $complement->amount = $original->amount?->negated();
                    $complement->foreign_amount = $original->foreign_amount?->negated();
                    $complement->atomic_type = $original->atomic_type == 'in' ? 'out' : 'in';

                    // Replace collection with both sides
                    $entries = collect([$original, $complement]);
                } else {
                    // Defensive logging for data anomalies
                    \Log::warning("Unmatched stage transaction {$stageTx->id} has {$entries->count()} entries; skipping complement synthesis.");
                }
            }

            // Create finalized transaction
            $transaction = Transaction::create([
                'transaction_at' => $stageTx->tx_at,
                'tx_type' => $stageTx->tx_type ?? TransactionType::Send,
                'description' => $stageTx->description,
                'address' => $stageTx->address,
            ]);

            // Import both sides of the transaction
            foreach ($entries as $entry) {
                $wallet = $entry->wallet
                    ? $entry->wallet
                    : $this->walletService->getOrCreateExternalWallet(
                        $entry->foreign_currency ?? $entry->currency
                    );

                WalletEntry::create([
                    'transaction_id' => $transaction->id,
                    'transaction_at' => $transaction->transaction_at,
                    'wallet_id' => $wallet?->id,
                    'entry_type' => $entry->atomic_type,
                    'amount' => $entry->amount,
                    'currency' => $entry->currency,
                    'foreign_amount' => $entry->foreign_amount,
                    'foreign_currency' => $entry->foreign_currency,
                ]);
            }

            if ($feeEntry && ! $feeEntry->effectiveAmount()->isZero()) {
                $wallet = $feeEntry->wallet
                    ? $feeEntry->wallet
                    : $this->walletService->getOrCreateExternalWallet(
                        $feeEntry->foreign_currency ?? $feeEntry->currency
                    );
                WalletEntry::create([
                    'transaction_id' => $transaction->id,
                    'transaction_at' => $transaction->transaction_at,
                    'wallet_id' => $wallet?->id,
                    'entry_type' => 'fee',
                    'amount' => $feeEntry->amount,
                    'currency' => $feeEntry->currency,
                    'foreign_amount' => $feeEntry->foreign_amount,
                    'foreign_currency' => $feeEntry->foreign_currency,
                ]);
            }

            // Mark stage transaction as finalized
            $stageTx->update(['status' => StageTransaction::STATUS_CONFIRMED]);
        });
    }

    /**
     * @param  Collection<StageTransaction>  $transactions
     */
    private function matchByAmountAndDate(Collection $transactions): int
    {
        $numMatches = 0;
        $matchedIds = [];

        foreach ($transactions as $tx) {
            // skip if already matched (by previous iteration)
            if ($tx->isMatched() || in_array($tx->id, $matchedIds, true)) {
                continue;
            }

            $entry = $tx->entries()->first();
            if (! $entry || ! $entryMoney = $entry->effectiveAmount()) {
                continue;
            }

            $oppositeAmount = $entryMoney->negated();
            $atomicType = $entry->atomic_type === 'in' ? 'out' : 'in';
            $matches = $this->findMatchingEntries($entry->tx_at, $oppositeAmount, null, $atomicType, $entry->wallet_id);

            if (! count($matches)) {
                continue;
            }

            /** @var StageEntry $closest */
            $closest = $matches[0];
            $matchTx = $closest->stageTransaction;

            // Double-check: ensure not already matched
            if ($matchTx->isMatched() || in_array($matchTx->id, $matchedIds, true)) {
                continue;
            }

            // link both transactions
            DB::transaction(function () use ($tx, $matchTx) {
                $tx->update([
                    'match_tx_id' => $matchTx->id,
                    'status' => StageTransaction::STATUS_AUTOMATCHED,
                    'match_basis' => 'amount and date',
                ]);

                $matchTx->update([
                    'match_tx_id' => $tx->id,
                    'status' => StageTransaction::STATUS_AUTOMATCHED,
                    'match_basis' => 'amount and date',
                ]);
            });

            // mark both as matched in memory
            $matchedIds[] = $tx->id;
            $matchedIds[] = $matchTx->id;
            $numMatches++;
        }

        return $numMatches;
    }

    /**
     * @param  Collection<StageTransaction>  $transactions
     */
    private function matchByInternalWallet(Collection $transactions): int
    {
        $numMatches = 0;

        foreach ($transactions as $tx) {
            if ($tx->isMatched() || $tx->tx_type !== TransactionType::Send || ! $tx->address) {
                continue;
            }

            $otherWallet = Wallet::findByAddress($tx->address);
            if (! $otherWallet) {
                continue;
            }

            $thisEntry = $tx->entries->firstWhere('atomic_type', 'out');
            if (! $thisEntry || ! $thisEntry->wallet?->currency) {
                continue;
            }

            $thisCurrency = $thisEntry->wallet->currency;
            $otherCurrency = $otherWallet->currency;
            $amount = $thisCurrency == $otherCurrency ? $thisEntry->effectiveAmount() : null;

            $matchingEntries = $this->findMatchingEntries($tx->tx_at, $amount, $otherWallet, 'in', $thisEntry->wallet_id);
            if (! empty($matchingEntries)) {
                if (count($matchingEntries) > 1) {
                    \Log::warning("Multiple matches found for $tx->id — using first");
                }
                $otherEntry = $matchingEntries[0];
                $numMatches++;

                // link both transactions
                $tx->update([
                    'match_tx_id' => $otherEntry->stageTransaction->id,
                    'status' => StageTransaction::STATUS_AUTOMATCHED,
                    'match_basis' => 'wallet address',
                ]);

                $otherEntry->stageTransaction->update([
                    'match_tx_id' => $tx->id,
                    'status' => StageTransaction::STATUS_AUTOMATCHED,
                    'match_basis' => 'wallet address',
                ]);
            } elseif ($thisCurrency === $otherCurrency) {
                $stageTx = StageTransaction::create([
                    'num' => $this->nextTxNumber(),
                    'tx_at' => $tx->tx_at,
                    'match_tx_id' => $tx->id,
                    'tx_type' => TransactionType::Receive,
                    'description' => $tx->description,
                    'status' => StageTransaction::STATUS_AUTOMATCHED,
                    'match_basis' => 'wallet address',
                ]);
                StageEntry::create([
                    'stage_transaction_id' => $stageTx->id,
                    'tx_at' => $tx->tx_at,
                    'atomic_type' => 'in',
                    'wallet_id' => $otherWallet->id,
                    'amount' => $thisEntry->amount?->negated(),
                    'foreign_amount' => $thisEntry->foreign_amount?->negated(),
                ]);
                $tx->update([
                    'match_tx_id' => $stageTx->id,
                    'status' => StageTransaction::STATUS_AUTOMATCHED,
                ]);
            }
        }

        return $numMatches;
    }

    public function nextTxNumber(): int
    {
        return (StageTransaction::max('num') ?? 0) + 1;
    }
}
