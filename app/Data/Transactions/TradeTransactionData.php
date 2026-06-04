<?php

namespace App\Data\Transactions;

use App\Enums\TransactionType;

class TradeTransactionData extends BaseTransactionData
{
    public ?int $platform_id = null;

    public ?int $src_wallet_id = null;

    public ?int $dst_wallet_id = null;

    public string $src_amount;

    public string $src_currency;

    public string $dst_amount;

    public string $dst_currency;

    public ?string $fee_amount = null;

    public ?string $fee_currency = null;

    public static function tx_type(): TransactionType
    {
        return TransactionType::Trade;
    }
}
