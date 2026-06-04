<?php

namespace App\Enums;

use App\Models\Transaction;
use App\Models\WalletEntry;

enum TransactionType: string
{
    case Trade = 'trade';
    case Receive = 'receive';
    case Send = 'send';
    case Transfer = 'transfer';
    case Borrow = 'borrow';
    case Repayment = 'repayment';

    public function classify(WalletEntry $entry, Transaction $tx): ?string
    {
        return match ($this) {
            self::Trade => $entry->isPositive() ? 'acquisition' : 'disposal',
            self::Receive => $tx->is_income ? 'income' : 'acquisition',
            self::Send => 'disposal',
            self::Transfer => null,
        };
    }
}
