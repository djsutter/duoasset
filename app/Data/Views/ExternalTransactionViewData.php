<?php

namespace App\Data\Views;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Types\Money;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

class ExternalTransactionViewData extends Data
{
    public function __construct(
        public int $id,
        public Carbon $transaction_at,
        public TransactionType $tx_type,
        public string $description,
        public ?Money $amount,
        public ?Money $foreign_amount,
        public Wallet $wallet,
    ) {}

    public static function fromModel(Transaction $transaction, WalletEntry $entry): ExternalTransactionViewData
    {
        return new self(
            id: $transaction->id,
            transaction_at: $transaction->transaction_at,
            tx_type: $transaction->tx_type,
            description: $transaction->description,
            amount: $entry->amount,
            foreign_amount: $entry->foreign_amount,
            wallet: $entry->wallet,
        );
    }
}
