<?php

namespace App\Data\Mappers;

use App\Data\Imports\GenericIORowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class GenericIOMapper extends BaseMapper
{
    public function map(GenericIORowData $dto): ?StageTransactionImportData
    {
        return match ($dto->direction) {
            'in' => $this->mapReceive($dto),
            'out' => $this->mapSend($dto),
            default => null,
        };
    }

    public function mapReceive(GenericIORowData $dto): ?StageTransactionImportData
    {
        $wallet = $this->walletService->getOrCreateWallet($this->platform, $dto->asset);

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Receive,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Deposit $dto->asset into $this->platformName",
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $wallet->id,
            foreign_amount: $this->toMoney($dto->amount, $dto->asset),
        ));

        return $txDto;
    }

    public function mapSend(GenericIORowData $dto): ?StageTransactionImportData
    {
        $wallet = $this->walletService->getOrCreateWallet($this->platform, $dto->asset);

        $amount = $this->toMoney($dto->amount, $dto->asset)->negated();
        $fee = $this->toMoney($dto->fee, $dto->asset)->negated();

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Send,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Withdraw $dto->asset from $this->platformName",
            address: $dto->address,
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $wallet->id,
            foreign_amount: $amount->subtract($fee),
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'fee',
            wallet_id: $wallet->id,
            foreign_amount: $fee,
        ));

        return $txDto;
    }
}
