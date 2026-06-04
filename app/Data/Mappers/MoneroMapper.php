<?php

namespace App\Data\Mappers;

use App\Data\Imports\MoneroRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class MoneroMapper extends BaseMapper
{
    protected string $platformName = 'Monero';

    public function map(MoneroRowData $dto): ?StageTransactionImportData
    {
        return match ($dto->direction) {
            'in' => $this->mapReceive($dto),
            'out' => $this->mapSend($dto),
            default => null,
        };
    }

    private function mapReceive(MoneroRowData $dto): ?StageTransactionImportData
    {
        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Receive,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Receive $this->asset into $this->platformName",
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $this->wallet->id,
            foreign_amount: $this->toMoney($dto->amount),
        ));

        // Monero records all fees, including 'in' transactions, so preserve these because they might be useful later.
        if ($dto->fee) {
            $txDto->addEntry(new StageEntryImportData(
                tx_at: $txDto->tx_at,
                atomic_type: 'sender-fee',
                wallet_id: $this->wallet->id,
                foreign_amount: $this->toMoney($dto->fee)->negated(),
            ));
        }

        return $txDto;
    }

    private function mapSend(MoneroRowData $dto): ?StageTransactionImportData
    {
        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Send,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Send $this->asset from $this->platformName",
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $this->wallet->id,
            foreign_amount: $this->toMoney($dto->amount)->negated(),
        ));

        if ($dto->fee) {
            $txDto->addEntry(new StageEntryImportData(
                tx_at: $txDto->tx_at,
                atomic_type: 'fee',
                wallet_id: $this->wallet->id,
                foreign_amount: $this->toMoney($dto->fee)->negated(),
            ));
        }

        return $txDto;
    }
}
