<?php

namespace App\Data\Transactions;

use App\Enums\TransactionType;
use InvalidArgumentException;

final class TransactionEditDataFactory
{
    public static function fromArray(array $data): BaseTransactionData
    {
        return match ($data['tx_type']) {
            TransactionType::Send => SendTransactionData::fromArray($data),
            TransactionType::Receive => ReceiveTransactionData::fromArray($data),
            TransactionType::Trade => TradeTransactionData::fromArray($data),
            TransactionType::Transfer => TransferTransactionData::fromArray($data),
            default => throw new InvalidArgumentException("Unknown transaction type: {$data['tx_type']}")
        };
    }
}
