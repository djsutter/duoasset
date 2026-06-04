<?php

namespace App\Data\Mappers;

use App\Data\Imports\PlatformTransferRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class PlatformTransferMapper extends BaseMapper
{
    private string $reportingCurrency;

    public function map(PlatformTransferRowData $dto): ?StageTransactionImportData
    {
        $this->reportingCurrency = getReportingCurrency();

        return match ($dto->direction) {
            'IN' => $this->mapReceive($dto),
            'OUT' => $this->mapSend($dto),
            default => null,
        };
    }

    public function mapReceive(PlatformTransferRowData $dto): ?StageTransactionImportData
    {
        $wallet = $this->walletService->getOrCreateWallet($this->platform, $dto->asset);
        $isForeign = $dto->asset != $this->reportingCurrency;
        $amount = $isForeign ? null : $this->toMoney($dto->amount, $dto->asset);
        $foreignAmount = $isForeign ? $this->toMoney($dto->amount, $dto->asset) : null;

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
            amount: $amount,
            foreign_amount: $foreignAmount,
        ));

        return $txDto;
    }

    public function mapSend(PlatformTransferRowData $dto): ?StageTransactionImportData
    {
        $wallet = $this->walletService->getOrCreateWallet($this->platform, $dto->asset);
        $isForeign = $dto->asset != $this->reportingCurrency;
        $amount = $isForeign ? null : $this->toMoney($dto->amount, $dto->asset);
        $foreignAmount = $isForeign ? $this->toMoney($dto->amount, $dto->asset) : null;
        $fee = $isForeign ? null : $this->toMoney($dto->fee, $dto->asset)->negated();
        $foreignFee = $isForeign ? $this->toMoney($dto->fee, $dto->asset)->negated() : null;

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
            amount: $amount,
            foreign_amount: $foreignAmount?->subtract($fee),
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'fee',
            wallet_id: $wallet->id,
            amount: $fee,
            foreign_amount: $foreignFee,
        ));

        return $txDto;
    }
}
