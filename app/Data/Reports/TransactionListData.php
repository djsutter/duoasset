<?php

namespace App\Data\Reports;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Types\Money;
use Carbon\Carbon;

class TransactionListData
{
    public function __construct(
        public readonly int $tx_id,
        public readonly Carbon $date,
        public readonly TransactionType $tx_type,
        public readonly string $description,
        public readonly ?Money $src_amount,
        public readonly ?Money $dst_amount,
        public readonly ?Money $src_foreign_amount,
        public readonly ?Money $dst_foreign_amount,
        public readonly ?string $src_wallet,
        public readonly ?string $dst_wallet,
    ) {}

    public static function fromModel(Transaction $transaction): self
    {
        $src_amount = null;
        $dst_amount = null;
        $src_foreign_amount = null;
        $dst_foreign_amount = null;

        foreach ($transaction->entries as $entry) {
            if ($entry->entry_type == 'in') {
                $dst_amount = $entry->amount;
                $dst_foreign_amount = $entry->foreign_amount;
                $dst_wallet = $entry->wallet->isExternal() ? 'external wallet' : $entry->wallet->platform?->name;
            } elseif ($entry->entry_type == 'out') {
                $src_amount = $entry->amount;
                $src_foreign_amount = $entry->foreign_amount;
                $src_wallet = $entry->wallet->isExternal() ? 'external wallet' : $entry->wallet->platform?->name;
            }
        }

        return new self(
            $transaction->id,
            $transaction->transaction_at,
            $transaction->tx_type,
            $transaction->description,
            $src_amount,
            $dst_amount,
            $src_foreign_amount,
            $dst_foreign_amount,
            $src_wallet,
            $dst_wallet,
        );
    }

    public function dateIso(): string
    {
        return $this->date->toDateString(); // 2025-09-07
    }

    public function dateHuman(): string
    {
        return $this->date->format('M j, Y g:i A'); // Sep 7, 2025 5:30 PM
    }

    public function dstAmountFormatted(): string
    {
        if (! $this->dst_amount) {
            return '';
        }

        return $this->dst_amount->format().' '.$this->dst_amount->currency;
    }

    public function srcAmountFormatted(): string
    {
        if (! $this->src_amount) {
            return '';
        }

        return $this->src_amount->format().' '.$this->src_amount->currency;
    }

    public function dstForeignAmountFormatted(): string
    {
        if (! $this->dst_foreign_amount) {
            return '';
        }

        return $this->dst_foreign_amount->format().' '.$this->dst_foreign_amount->currency;
    }

    public function srcForeignAmountFormatted(): string
    {
        if (! $this->src_foreign_amount) {
            return '';
        }

        return $this->src_foreign_amount->format().' '.$this->src_foreign_amount->currency;
    }
}
