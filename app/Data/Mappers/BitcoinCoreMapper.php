<?php

namespace App\Data\Mappers;

use App\Data\Imports\BitcoinCoreRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class BitcoinCoreMapper extends BaseMapper
{
    protected string $platformName = 'Bitcoin Core';

    public function map(BitcoinCoreRowData $dto): ?StageTransactionImportData
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

    private function mapReceive(BitcoinCoreRowData $dto): ?StageTransactionImportData
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
            foreign_amount: $this->toMoney($dto->amount_btc),
        ));

        return $txDto;
    }

    private function mapSend(BitcoinCoreRowData $dto): ?StageTransactionImportData
    {
        // amountBtc is negative and includes the fee
        $amountBtc = $this->toMoney($dto->amount_btc);
        $fee = $this->toMoney($dto->fee)->negated();
        // Note that TreasureChest uses the exact same CSV layout but slightly different fee treatment, maybe.
        $amountBtc = $amountBtc->subtract($fee);

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
            foreign_amount: $amountBtc,
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
