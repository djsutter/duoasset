<?php

namespace App\Livewire\Transactions;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\WalletEntry;
use App\Services\ReplayService;
use App\Services\WalletService;
use App\Traits\SendsNotifications;
use Livewire\Component;

class External extends Component
{
    use SendsNotifications;

    public array $sel_tx = [];

    public array $replayLog = [];

    protected $listeners = [
        'do-change-description' => 'changeDescription',
    ];

    public function changeDescription($description, ReplayService $replayService): void
    {
        $transactions = [];

        foreach ($this->sel_tx as $id) {
            if ($transaction = Transaction::find($id)) {
                $transaction->update([
                    'description' => $description,
                ]);
                $transactions[] = $replayService->getTransactionKey($transaction);
            }
        }

        $this->replayLog[] = [
            'action' => 'update_description',
            'data' => [
                'description' => $description,
                'transactions' => $transactions,
            ],
        ];

        $this->sel_tx = [];

        $this->success('Descriptions updated.');
    }

    public function joinSelected(ReplayService $replayService): void
    {
        if (count($this->sel_tx) !== 2) {
            $this->error('Select exactly two transactions.');

            return;
        }

        /** @var \App\Models\Transaction $t1 */
        /** @var \App\Models\Transaction $t2 */
        [$t1, $t2] = Transaction::with('entries.wallet')->findMany($this->sel_tx)->all();

        // 1. Choose source (earliest timestamp)
        $source = $t1->transaction_at <= $t2->transaction_at ? $t1 : $t2;
        $target = $source === $t1 ? $t2 : $t1;

        // 2. Filter out external wallets for proper in/out entries
        $sourceInternal = $source->entries->filter(fn ($e) => $e->wallet->type !== 'external');
        $targetInternal = $target->entries->filter(fn ($e) => $e->wallet->type !== 'external');

        // 3. Extract main entries
        $srcOut = $sourceInternal->firstWhere('entry_type', 'out');
        $srcIn = $sourceInternal->firstWhere('entry_type', 'in');
        $srcFee = $source->entries->firstWhere('entry_type', 'fee'); // fee can be external or internal

        $tgtOut = $targetInternal->firstWhere('entry_type', 'out');
        $tgtIn = $targetInternal->firstWhere('entry_type', 'in');
        $tgtFee = $target->entries->firstWhere('entry_type', 'fee');

        // 4. Normalize: determine outEntry and inEntry
        $outEntry = $srcOut ?: $tgtOut;
        $inEntry = $srcIn ?: $tgtIn;

        if (! $outEntry || ! $inEntry) {
            $this->error('Unable to determine in/out entries.');

            return;
        }

        // 5. Determine TRANSFER vs TRADE
        $isTransfer = $outEntry->foreign_currency === $inEntry->foreign_currency;

        // 6. Create new joined transaction
        $newTx = Transaction::create([
            'transaction_at' => $source->transaction_at,
            'tx_type' => $isTransfer ? TransactionType::Transfer : TransactionType::Trade,
            'description' => $isTransfer ? 'Joined transfer' : 'Joined trade',
        ]);

        // 7. Recreate WalletEntry rows
        $entries = [];

        // OUT entry
        $entries[] = [
            'transaction_id' => $newTx->id,
            'transaction_at' => $newTx->transaction_at,
            'wallet_id' => $outEntry->wallet_id,
            'entry_type' => 'out',
            'amount' => $outEntry->amount,
            'foreign_amount' => $outEntry->foreign_amount,
        ];

        // IN entry
        $entries[] = [
            'transaction_id' => $newTx->id,
            'transaction_at' => $newTx->transaction_at,
            'wallet_id' => $inEntry->wallet_id,
            'entry_type' => 'in',
            'amount' => $inEntry->amount,
            'foreign_amount' => $inEntry->foreign_amount,
        ];

        // FEE entry (from source first, fallback target)
        $feeEntry = $srcFee ?: $tgtFee;
        if ($feeEntry) {
            $entries[] = [
                'transaction_id' => $newTx->id,
                'transaction_at' => $newTx->transaction_at,
                'wallet_id' => $feeEntry->wallet_id,
                'entry_type' => 'fee',
                'amount' => $feeEntry->amount,
                'foreign_amount' => $feeEntry->foreign_amount,
            ];
        }

        // Persist entries individually (to preserve Money casts)
        foreach ($entries as $entry) {
            WalletEntry::create($entry);
        }

        // 8. Record replay log
        $this->replayLog[] = [
            'action' => 'join',
            'data' => [
                'tx_type' => $newTx->tx_type->value,
                'source' => $replayService->getTransactionKey($source),
                'target' => $replayService->getTransactionKey($target),
                'description' => $newTx->description,
            ],
        ];

        // 9. Delete originals
        $t1->entries()->delete();
        $t2->entries()->delete();
        $t1->delete();
        $t2->delete();

        // 10. Reset selection
        $this->sel_tx = [];

        $this->success(ucfirst($newTx->tx_type->value).' created and originals deleted.');
    }

    public function exportReplayLog()
    {
        if (empty($this->replayLog)) {
            $this->error('Replay log is empty.');

            return;
        }

        // Ensure deterministic ordering
        $log = collect($this->replayLog)
            ->sortBy([
                ['source.transaction_at', 'asc'],
                ['source.wallet_id', 'asc'],
                ['source.foreign_amount', 'asc'],
            ])
            ->values()
            ->toArray();

        $json = json_encode($log, JSON_PRETTY_PRINT);

        $filename = 'replay-log-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(
            function () use ($json) {
                echo $json;
            },
            $filename,
            [
                'Content-Type' => 'application/json',
            ]
        );
    }

    public function render(WalletService $walletService)
    {
        $transactions = $walletService->getExternalTransactions();

        return view('livewire.transactions.external', [
            'transactions' => $transactions,
        ]);
    }
}
