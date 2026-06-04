<?php

namespace App\Services;

use App\Actions\CreateTransaction;
use App\Actions\UpdateTransaction;
use App\Data\Transactions\BaseTransactionData;
use App\Enums\TransactionType;
use App\Services\Transactions\Entries\EntryBuilderInterface;
use App\Services\Transactions\Entries\ReceiveEntryBuilder;
use App\Services\Transactions\Entries\SendEntryBuilder;
use App\Services\Transactions\Entries\TradeEntryBuilder;
use App\Services\Transactions\Entries\TransferEntryBuilder;

class TransactionService
{
    private array $builderCache = [];

    public function resolveBuilder(TransactionType|string $txType): EntryBuilderInterface
    {
        $txType = $txType instanceof TransactionType ? $txType : TransactionType::from($txType);

        return $this->builderCache[$txType->value]
            ??= match ($txType) {
                TransactionType::Receive => new ReceiveEntryBuilder,
                TransactionType::Send => new SendEntryBuilder,
                TransactionType::Transfer => new TransferEntryBuilder,
                TransactionType::Trade => new TradeEntryBuilder,
                default => throw new \InvalidArgumentException("Unsupported transaction txType: {$txType->value}"),
            };
    }

    public function save(BaseTransactionData $dto)
    {
        return $dto->id
            ? app(UpdateTransaction::class)->execute($dto)
            : app(CreateTransaction::class)->execute($dto);
    }
}
