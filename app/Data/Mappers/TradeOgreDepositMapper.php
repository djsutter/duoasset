<?php

namespace App\Data\Mappers;

use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Data\Imports\TradeOgreDepositRowData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class TradeOgreDepositMapper extends BaseMapper
{
    protected string $platformName = 'TradeOgre';

    public function map(TradeOgreDepositRowData $dto): ?StageTransactionImportData
    {
        $wallet = $this->walletService->getOrCreateWallet($this->platform, $dto->coin);

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Receive,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Deposit $dto->coin into $this->platformName",
            source: $this->platformName,
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: 'in',
            wallet_id: $wallet->id,
            foreign_amount: $this->toMoney($dto->amount, $dto->coin),
        ));

        return $txDto;
    }
}
