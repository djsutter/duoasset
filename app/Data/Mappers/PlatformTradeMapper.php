<?php

namespace App\Data\Mappers;

use App\Data\Imports\PlatformTradeRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class PlatformTradeMapper extends BaseMapper
{
    private string $reportingCurrency;

    public function map(PlatformTradeRowData $dto): ?StageTransactionImportData
    {
        $this->reportingCurrency = getReportingCurrency();

        return match ($dto->type) {
            'BUY' => $this->mapBuyTransaction($dto),
            'SELL' => $this->mapSellTransaction($dto),
            default => null,
        };
    }

    public function mapBuyTransaction(PlatformTradeRowData $dto): ?StageTransactionImportData
    {
        [$sellAsset, $buyAsset] = explode('-', $dto->pair);

        $sellWallet = $this->walletService->getOrCreateWallet($this->platform, $sellAsset);
        $buyWallet = $this->walletService->getOrCreateWallet($this->platform, $buyAsset);
        $buyAmount = $this->toMoney($dto->amount, $buyAsset);
        $price = $this->toMoney($dto->price, $sellAsset);
        $sellAmount = $price->multiply($buyAmount);
        if ($dto->fee_currency == 'CAD') {
            $cadFee = $this->toMoney($dto->fee, $dto->fee_currency)->negated();
            $foreignFee = null;
            $feeWallet = $sellWallet->id;
        } else {
            $cadFee = $this->toMoney($dto->fee_cad_value, 'CAD')->negated();
            $foreignFee = $this->toMoney($dto->fee, $dto->fee_currency)->negated();
            $feeWallet = $buyWallet->id;
        }

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
            amount: $sellAmount->currency == $this->reportingCurrency ? $sellAmount->negated() : null,
            foreign_amount: $sellAmount->currency == $this->reportingCurrency ? null : $sellAmount->negated(),
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $buyWallet->id,
            amount: $buyAmount->currency == $this->reportingCurrency ? $buyAmount : null,
            foreign_amount: $buyAmount->currency == $this->reportingCurrency ? null : $buyAmount,
        ));

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'fee',
            wallet_id: $feeWallet,
            amount: $cadFee,
            foreign_amount: $foreignFee,
        ));

        return $txDto;
    }

    public function mapSellTransaction(PlatformTradeRowData $dto): ?StageTransactionImportData
    {
        [$buyAsset, $sellAsset] = explode('-', $dto->pair);
        $buyWallet = $this->walletService->getOrCreateWallet($this->platform, $buyAsset);
        $sellWallet = $this->walletService->getOrCreateWallet($this->platform, $sellAsset);
        $sellAmount = $this->toMoney($dto->amount, $sellAsset);
        $buyAmount = $this->toMoney($dto->price, $buyAsset);
        if ($dto->fee_currency == 'CAD') {
            $cadFee = $this->toMoney($dto->fee, $dto->fee_currency)->negated();
            $foreignFee = null;
            $feeWallet = $buyWallet->id;
        } else {
            $cadFee = $this->toMoney($dto->fee_cad_value, 'CAD')->negated();
            $foreignFee = $this->toMoney($dto->fee, $dto->fee_currency)->negated();
            $feeWallet = $sellWallet->id;
        }

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
            wallet_id: $feeWallet,
            amount: $cadFee,
            foreign_amount: $foreignFee,
        ));

        return $txDto;
    }
}
