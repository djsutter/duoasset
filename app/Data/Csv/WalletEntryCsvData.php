<?php

namespace App\Data\Csv;

final class WalletEntryCsvData
{
    public function __construct(
        public readonly string $transaction_at,
        public readonly ?string $source_transaction_id,
        public readonly string $wallet_id,
        public readonly string $entry_type,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $foreign_amount = null,
        public readonly ?string $foreign_currency = null,
        public readonly ?string $fee_amount = null,
        public readonly ?string $fee_currency = null,
        public readonly ?string $description = null,
    ) {}

    public static function headers(): array
    {
        return [
            'transaction_at',
            'source_transaction_id',
            'wallet_id',
            'entry_type',
            'amount',
            'currency',
            'foreign_amount',
            'foreign_currency',
            'fee_amount',
            'fee_currency',
            'description',
        ];
    }

    public function toArray(): array
    {
        return [
            $this->transaction_at,
            $this->source_transaction_id,
            $this->wallet_id,
            $this->entry_type,
            $this->amount,
            $this->currency,
            $this->foreign_amount,
            $this->foreign_currency,
            $this->fee_amount,
            $this->fee_currency,
            $this->description,
        ];
    }
}
