<?php

namespace App\Data\Transactions;

use App\Enums\TransactionType;

class SendTransactionData extends BaseTransactionData
{
    public int $src_wallet_id;

    public string $src_amount;

    public ?string $fee_amount = null;

    public ?string $fee_currency = null;

    public static function tx_type(): TransactionType
    {
        return TransactionType::Send;
    }
}
