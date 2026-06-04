<?php

namespace App\Data\Transactions;

use App\Enums\TransactionType;

class ReceiveTransactionData extends BaseTransactionData
{
    public int $dst_wallet_id;

    public string $dst_amount;

    public string $dst_currency;

    public static function tx_type(): TransactionType
    {
        return TransactionType::Receive;
    }
}
