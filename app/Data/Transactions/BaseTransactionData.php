<?php

namespace App\Data\Transactions;

use App\Enums\TransactionType;

abstract class BaseTransactionData
{
    public TransactionType $tx_type;

    public ?int $id = null;

    public string $transaction_at;

    public ?string $description = null;

    public ?string $address = null;

    abstract public static function tx_type(): TransactionType;

    public static function fromArray(array $data): static
    {
        $dto = new static;
        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                $dto->$key = $value;
            }
        }
        $dto->tx_type = static::tx_type();

        return $dto;
    }
}
