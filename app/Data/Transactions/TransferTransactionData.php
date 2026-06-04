<?php

namespace App\Data\Transactions;

use App\Enums\TransactionType;

class TransferTransactionData extends BaseTransactionData
{
    public int $src_wallet_id;

    public string $src_amount;

    public string $src_currency;

    public int $dst_wallet_id;

    public string $dst_amount;

    public string $dst_currency;

    public ?string $fee_amount = null;

    public ?string $fee_currency = null;

    public static function tx_type(): TransactionType
    {
        return TransactionType::Transfer;
    }
}
