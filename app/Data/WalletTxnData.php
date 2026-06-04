<?php

namespace App\Data;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Types\Money;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

class WalletTxnData extends Data
{
    public function __construct(
        public int $transactionId,
        public Carbon $transactionAt,
        public TransactionType $tx_type,
        public string $description,
        public Money $amount,
        public Money $balance,
        public ?string $otherWallet = null,
    ) {}

    public static function fromModel(Transaction $transaction, Money $totalAmount, Money $balance, ?string $otherWallet = null): WalletTxnData
    {
        return new self(
            transactionId: $transaction->id,
            transactionAt: $transaction->transaction_at,
            tx_type: $transaction->tx_type,
            description: $transaction->description,
            amount: $totalAmount,
            balance: $balance,
            otherWallet: $otherWallet,
        );
    }
}
