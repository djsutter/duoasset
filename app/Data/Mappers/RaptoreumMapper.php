<?php

namespace App\Data\Mappers;

use App\Data\Imports\RaptoreumRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class RaptoreumMapper extends BaseMapper
{
    protected string $platformName = 'Raptoreum';

    public function map(RaptoreumRowData $dto): ?StageTransactionImportData
    {
        if ($dto->confirmed === 'false') {
            return null;
        }

        return match ($dto->type) {
            'Received with' => $this->mapReceive($dto),
            'Sent to' => $this->mapSend($dto),
            default => null,
        };
    }

    private function mapReceive(RaptoreumRowData $dto): ?StageTransactionImportData
    {
        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Receive,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Receive {$this->asset} into {$this->platformName}/{$this->wallet->name}",
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $this->wallet->id,
            foreign_amount: $this->toMoney($dto->amount),
        ));

        return $txDto;
    }

    private function mapSend(RaptoreumRowData $dto): ?StageTransactionImportData
    {
        $amount = $this->toMoney($dto->amount);
        if ($dto->fee) {
            $fee = $this->toMoney($dto->fee);
        }

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Send,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Send {$this->asset} from {$this->platformName}/{$this->wallet->name}",
            address: $dto->address,
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $this->wallet->id,
            foreign_amount: $amount,
        ));

        if ($dto->fee) {
            $txDto->addEntry(new StageEntryImportData(
                tx_at: $txDto->tx_at,
                atomic_type: 'fee',
                wallet_id: $this->wallet->id,
                foreign_amount: $fee,
            ));
        }

        return $txDto;
    }
}
