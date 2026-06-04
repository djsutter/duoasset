<?php

namespace App\Data\Mappers;

use App\Data\Imports\ExodusRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class ExodusMapper extends BaseMapper
{
    protected string $platformName = 'Exodus';

    public function map(ExodusRowData $dto): ?StageTransactionImportData
    {
        return match ($dto->type) {
            'deposit' => $this->mapReceive($dto),
            'withdrawal' => $this->mapSend($dto),
            default => null,
        };
    }

    private function mapReceive(ExodusRowData $dto): ?StageTransactionImportData
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
            foreign_amount: $this->toMoney($dto->coin_amount),
        ));

        return $txDto;
    }

    private function mapSend(ExodusRowData $dto): ?StageTransactionImportData
    {
        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Send,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Send $this->asset from $this->platformName",
            address: $dto->address,
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $this->wallet->id,
            foreign_amount: $this->toMoney($dto->coin_amount),
        ));

        if ($dto->fee) {
            $txDto->addEntry(new StageEntryImportData(
                tx_at: $txDto->tx_at,
                atomic_type: 'fee',
                wallet_id: $this->wallet->id,
                foreign_amount: $this->toMoney($dto->fee), // Already negative
            ));
        }

        return $txDto;
    }
}
