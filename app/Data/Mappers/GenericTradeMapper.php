<?php

namespace App\Data\Mappers;

use App\Data\Imports\GenericTradeRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class GenericTradeMapper extends BaseMapper
{
    public function map(GenericTradeRowData $dto): ?StageTransactionImportData
    {
        return match ($dto->type) {
            'BUY' => $this->mapBuyTransaction($dto),
            'SELL' => $this->mapSellTransaction($dto),
            default => null,
        };
    }

    public function mapBuyTransaction(GenericTradeRowData $dto): ?StageTransactionImportData
    {
        [$sellAsset, $buyAsset] = explode('-', $dto->exchange);

        $sellWallet = $this->walletService->getOrCreateWallet($this->platform, $sellAsset);
        $buyWallet = $this->walletService->getOrCreateWallet($this->platform, $buyAsset);
        $buyAmount = $this->toMoney($dto->proceeds, $buyAsset);
        $sellAmount = $this->toMoney($dto->cost, $sellAsset);
        $fee = $this->toMoney($dto->fee, $sellAsset);

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Trade,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_MATCHED,
            description: "Buy $buyAsset",
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $sellWallet->id,
            foreign_amount: $sellAmount->negated(),
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $buyWallet->id,
            foreign_amount: $buyAmount,
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'fee',
            wallet_id: $sellWallet->id,
            foreign_amount: $fee->negated(),
        ));

        return $txDto;
    }

    public function mapSellTransaction(GenericTradeRowData $dto): ?StageTransactionImportData
    {
        [$buyAsset, $sellAsset] = explode('-', $dto->exchange);
        $buyWallet = $this->walletService->getOrCreateWallet($this->platform, $buyAsset);
        $sellWallet = $this->walletService->getOrCreateWallet($this->platform, $sellAsset);
        $sellAmount = $this->toMoney($dto->cost, $sellAsset);
        $buyAmount = $this->toMoney($dto->proceeds, $buyAsset);
        $fee = $this->toMoney($dto->fee, $sellAsset);

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Trade,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_MATCHED,
            description: "Sell $sellAsset",
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'out',
            wallet_id: $sellWallet->id,
            foreign_amount: $sellAmount->negated(),
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $buyWallet->id,
            foreign_amount: $buyAmount,
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'fee',
            wallet_id: $buyWallet->id,
            foreign_amount: $fee->negated(),
        ));

        return $txDto;
    }
}
