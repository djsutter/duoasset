<?php

namespace App\Data\ACB;

use App\Enums\AcbEventType;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\WalletEntry;
use App\Types\Money;
use Carbon\Carbon;

class AcbEventData
{
    public function __construct(
        public int $tx_id,
        public Carbon $tx_at,
        public AcbEventType $event_type,
        public ?Money $amount,
        public ?Money $foreign_amount,
    ) {}

    public static function acquisition(
        Transaction $tx,
        WalletEntry $in,
        ?WalletEntry $fee
    ): self {
        $cadFee = self::cadFee($fee);

        $cryptoFee = (
            $fee &&
            $fee->foreign_amount &&
            $fee->foreign_currency === $in->foreign_currency
        )
            ? $fee->foreign_amount
            : null;

        return new self(
            tx_id: $tx->id,
            tx_at: $tx->transaction_at,
            event_type: AcbEventType::Acquisition,

            // CAD cost increases by any CAD fee
            amount: $in->amount
                ? $in->amount->add($cadFee)
                : null,

            // Only subtract crypto fee if same asset
            foreign_amount: $cryptoFee
                ? $in->foreign_amount->subtract($cryptoFee)
                : $in->foreign_amount,
        );
    }

    public static function disposal(
        Transaction $tx,
        WalletEntry $out,
        ?WalletEntry $fee
    ): self {
        $cadFee = self::cadFee($fee);

        $cryptoFee = (
            $fee &&
            $fee->foreign_amount &&
            $fee->foreign_currency === $out->foreign_currency
        )
            ? $fee->foreign_amount
            : null;

        return new self(
            tx_id: $tx->id,
            tx_at: $tx->transaction_at,
            event_type: AcbEventType::Disposal,

            // Proceeds reduced by CAD fee
            amount: $out->amount
                ? $out->amount->subtract($cadFee)
                : null,

            // Quantity reduced by crypto fee if same asset
            foreign_amount: $cryptoFee
                ? $out->foreign_amount->add($cryptoFee)
                : $out->foreign_amount,
        );
    }

    private static function cadFee(?WalletEntry $fee): Money
    {
        return $fee?->amount
            ? $fee->amount->abs()
            : Money::zero('CAD');
    }

    public static function transferFeeFromEntry(WalletEntry $entry): self
    {
        if ($entry->transaction->tx_type !== TransactionType::Transfer) {
            throw new \LogicException('transferFeeFromEntry() used for non-transfer transaction.');
        }

        if ($entry->entry_type !== 'fee') {
            throw new \LogicException('transferFeeFromEntry() used for non-fee entry.');
        }

        $foreignQty = $entry->foreign_amount?->abs();

        if (! $foreignQty) {
            // CAD-only transfer fee → not ACB relevant
            throw new \LogicException('Transfer fee without foreign amount should not reach ACB.');
        }

        return new self(
            tx_id: $entry->transaction_id,
            tx_at: $entry->transaction_at,
            event_type: AcbEventType::TransferFee,
            amount: null,                 // proceeds = 0
            foreign_amount: $foreignQty,   // quantity disposed
        );
    }
}
