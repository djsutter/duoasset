<?php

namespace App\Services\Import;

use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\Platform;
use App\Models\StageEntry;
use App\Models\StageTransaction;
use App\Models\UploadedFile;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Types\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

class ImportService
{
    protected $kcevents = [];

    public function __construct(
        protected WalletService $walletService,
    ) {}

    /**
     * Main entry point for importing files exported from wallets and exchanges.
     */
    public function import(): void
    {
        StageTransaction::truncate();
        StageEntry::truncate();

        foreach (UploadedFile::all() as $file) {
            if (str_contains($file->filename, 'kraken')) {
                $path = "/home/duncan/workspace/laravel/crypto12/$file->directory/$file->filename";
                $this->importFile($path, $file->mapper, $file->wallet, $file->platform, $file->wallet_prefix);
            }
        }

        return;

        $this->importKuCoinEvents();

        // Import 1 Binance transaction, because we don't have an import csv.
        if ($platform = Platform::where('name', 'Binance')->first()) {
            $wallet = Wallet::where('platform_id', $platform->id)->where('currency', 'BTC')->first();
            $tx = StageTransaction::create([
                'tx_at' => '2021-04-19 12:00:00',
                'tx_type' => TransactionType::Send,
                'description' => 'Withdraw',
                'status' => StageTransaction::STATUS_UNMATCHED,
                'source' => 'Manual',
            ]);
            StageEntry::create([
                'stage_transaction_id' => $tx->id,
                'tx_at' => $tx->tx_at,
                'atomic_type' => 'out',
                'wallet_id' => $wallet->id,
                'amount' => null,
                'foreign_amount' => Money::fromDecimal('-0.0095', 'BTC'),
            ]);
            StageEntry::create([
                'stage_transaction_id' => $tx->id,
                'tx_at' => $tx->tx_at,
                'atomic_type' => 'fee',
                'wallet_id' => $wallet->id,
                'amount' => null,
                'foreign_amount' => Money::fromDecimal('-0.0005', 'BTC'),
            ]);
        }

        $this->renumberAll();
        $this->validateTransactions();
    }

    private function importFile(string $path, string $mapper, ?string $walletName, ?string $platformName, ?string $walletNamePrefix): void
    {
        if (! is_readable($path)) {
            return;
        }

        $mapperClass = 'App\\Data\\Mappers\\'.$mapper.'Mapper';
        if ($platformName == 'KuCoin') {
            $mapperClass = 'App\\Data\\Mappers\\KuCoinEventMapper';
            $rowClass = 'App\\Data\\Imports\\KuCoinRowData';
            $rows = $this->readCsv($path, $rowClass);
            $mapper = app($mapperClass, [
                'walletName' => $walletName,
                'platformName' => $platformName,
                'walletNamePrefix' => $walletNamePrefix,
            ]);
            $importType = '';
            if (str_contains($path, 'Funding')) {
                $importType = 'Funding';
            } elseif (str_contains($path, 'Trading')) {
                $importType = 'Trading';
            } elseif (str_contains($path, 'Margin')) {
                $importType = 'Margin';
            }
            $events = $mapper->mapAll(collect($rows), $importType)->sortBy('tx_at');
            foreach ($events as $event) {
                if ($event) {
                    $this->kcevents[$event->time][$event->event_type][] = $event;
                }
            }
        } else {
            $rowClass = 'App\\Data\\Imports\\'.$mapper.'RowData';
            $rows = $this->readCsv($path, $rowClass);
            $transactions = $this->mapRows($rows, app($mapperClass, [
                'walletName' => $walletName,
                'platformName' => $platformName,
                'walletNamePrefix' => $walletNamePrefix,
            ]))
                ->sortBy('tx_at');

            $this->persistTransactions($transactions);
        }
    }

    private function importKuCoinEvents(): void
    {
        ksort($this->kcevents);

        $platform = Platform::where('name', 'KuCoin')->first();
        $transactions = collect();

        foreach ($this->kcevents as $time => $timeEvents) {
            foreach ($timeEvents as $eventType => $relatedEvents) {
                switch ($eventType) {
                    case 'transfer':
                        $txType = TransactionType::Transfer;
                        $status = StageTransaction::STATUS_MATCHED;
                        $description = 'Transfer';
                        break;
                    case 'trade':
                        $txType = TransactionType::Trade;
                        $status = StageTransaction::STATUS_MATCHED;
                        $description = 'Trade';
                        break;
                    case 'borrow':
                        $txType = TransactionType::Receive;
                        // $txType = TransactionType::Borrow;
                        $status = StageTransaction::STATUS_MATCHED;
                        $description = 'Borrow';
                        break;
                    case 'repayment':
                        $txType = TransactionType::Send;
                        // $txType = TransactionType::Repayment;
                        $status = StageTransaction::STATUS_MATCHED;
                        $description = 'Repayment';
                        break;
                    case 'deposit':
                        $txType = TransactionType::Receive;
                        $status = StageTransaction::STATUS_UNMATCHED;
                        $description = 'Deposit';
                        break;
                    case 'withdraw':
                        $txType = TransactionType::Send;
                        $status = StageTransaction::STATUS_UNMATCHED;
                        $description = 'Withdraw';
                        break;
                }
                $txDto = new StageTransactionImportData(
                    tx_type: $txType,
                    // tx_at: Carbon::parse($time, 'America/Toronto')->utc(),
                    tx_at: Carbon::parse($time),
                    status: $status,
                    description: $description,
                    source: 'KuCoin',
                );
                foreach ($relatedEvents as $event) {
                    if ($eventType === 'trade' && $event->account_type === 'Liability') {
                        throw new \DomainException(
                            'Invariant violation: trade event cannot touch Liability accounts'
                        );
                    }

                    if (in_array($eventType, ['borrow', 'repayment'], true)) {
                        if ($event->account_type !== 'Margin') {
                            throw new \DomainException(
                                "Invariant violation: {$eventType} event must touch Liability accounts"
                            );
                        }
                        $walletName = 'Liability-'.$event->currency;
                        $wallet = Wallet::firstOrCreate(
                            ['platform_id' => $platform->id, 'name' => $walletName],
                            ['currency' => $event->currency]
                        );
                        $amount = Money::fromDecimal(round($event->amount, 8), $event->currency);
                        $txDto->addEntry(new StageEntryImportData(
                            tx_at: $txDto->tx_at,
                            atomic_type: $eventType == 'borrow' ? 'out' : 'in',
                            wallet_id: $wallet->id,
                            foreign_amount: $eventType == 'borrow' ? $amount->negated() : $amount,
                        ));
                    }

                    $walletName = $event->account_type.'-'.$event->currency;
                    $wallet = Wallet::firstOrCreate(
                        ['platform_id' => $platform->id, 'name' => $walletName],
                        ['currency' => $event->currency]
                    );
                    $amount = Money::fromDecimal(round($event->amount, 8), $event->currency);
                    $fee = ($event->fee && $event->fee > 0) ? Money::fromDecimal(round($event->fee, 8), $event->currency) : null;
                    $txDto->addEntry(new StageEntryImportData(
                        tx_at: $txDto->tx_at,
                        atomic_type: $event->side == 'Deposit' ? 'in' : 'out',
                        wallet_id: $wallet->id,
                        foreign_amount: $event->side == 'Deposit' ? $amount : $amount->negated(),
                    ));
                    if ($fee) {
                        $txDto->addEntry(new StageEntryImportData(
                            tx_at: $txDto->tx_at,
                            atomic_type: 'fee',
                            wallet_id: $wallet->id,
                            foreign_amount: $fee->negated(),
                        ));
                    }
                }
                $transactions[] = $txDto;
            }
        }

        $this->persistTransactions($transactions);
    }

    private function mapRows(array $rows, $mapper): Collection
    {
        return collect($rows)
            ->map(fn ($dto) => $mapper->map($dto))
            ->filter();
    }

    /**
     * Persist a collection of new transactions in the database.
     */
    private function persistTransactions(Collection $transactions): void
    {
        DB::transaction(function () use ($transactions) {
            foreach ($transactions as $txnDto) {
                $transaction = $txnDto->toModel();
                $transaction->save();
                $transaction->entries()->saveMany($txnDto->toEntryModels($transaction));
            }
        });
    }

    /**
     * Read a CSV file and return an array of Data records, based on the $dtoClass provided.
     */
    private function readCsv(string $path, string $dtoClass): array
    {
        $csv = Reader::createFromPath($path, 'r');
        // $csv->setDelimiter(';');
        $csv->setHeaderOffset(0); // first row as header

        $records = [];
        foreach ($csv->getRecords() as $row) {
            $records[] = $dtoClass::fromRow($row); // Data constructor maps array keys to props
        }

        return $records;
    }

    private function renumberAll(): void
    {
        DB::transaction(function () {
            $transactions = StageTransaction::orderBy('tx_at')
                ->orderBy('id')
                ->get(['id', 'tx_at']);

            $n = 1;

            foreach ($transactions as $tx) {
                $tx->update(['num' => $n++]);
            }
        });
    }

    private function validateTransactions(): void
    {
        foreach (StageTransaction::all() as $tx) {
            $issues = $this->validate($tx);
            foreach ($issues as $issue) {
                \Log::error($issue);
            }
        }
    }

    private function validate(StageTransaction $tx): array
    {
        $issues = [];

        // 1. Each entry must have at least one amount defined
        foreach ($tx->entries as $entry) {
            if ($entry->foreign_amount === null && $entry->amount === null) {
                $issues[] = "Entry {$entry->id} has both amount and foreign_amount null.";
            }
        }

        // 2. Basic trade sanity (avoid massive unbalanced ratios)
        $ins = $tx->entries->where('entry_type', 'in');
        $outs = $tx->entries->where('entry_type', 'out');

        $inSum = $ins->sum(fn ($e) => $e->foreign_amount?->abs()->getValue() ?? 0);
        $outSum = $outs->sum(fn ($e) => $e->foreign_amount?->abs()->getValue() ?? 0);

        if ($inSum > 0 && $outSum > 0) {
            $ratio = max($inSum, $outSum) / min($inSum, $outSum);
            if ($ratio > 100) { // tweak as needed
                $issues[] = sprintf(
                    'Unbalanced trade in tx %d (%.2f:1 ratio)',
                    $tx->id,
                    $ratio
                );
            }
        }

        // 3. Fees should always be negative (if non-null)
        foreach ($tx->entries->where('entry_type', 'fee') as $fee) {
            if ($fee->foreign_amount?->isPositive()) {
                $issues[] = "Fee entry {$fee->id} has positive foreign_amount.";
            }
            if ($fee->amount?->isPositive()) {
                $issues[] = "Fee entry {$fee->id} has positive amount.";
            }
        }

        // 4. Optional: warn if both sides missing valuation after background mode should have run
        // if ($this->isPostValuationPhase() && $tx->entries->contains(fn($e) => $e->amount === null)) {
        //     $issues[] = "Transaction {$tx->id} has unvalued entries after valuation phase.";
        // }

        return $issues;
    }
}
