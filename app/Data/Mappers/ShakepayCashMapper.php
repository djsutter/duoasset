<?php

namespace App\Data\Mappers;

use App\Data\Imports\ShakepayCashRowData;
use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class ShakepayCashMapper extends BaseMapper
{
    protected string $platformName = 'Shakepay';

    public function map(ShakepayCashRowData $dto): ?StageTransactionImportData
    {
        // Skip Buy and Sell - they will be covered in the crypto transactions
        return match ($dto->type) {
            'Buy' => null,
            'Sell' => null,
            'Interac e-Transfer' => $this->mapTransfer($dto),
            'Reward' => $this->mapTransfer($dto),
            default => null,
        };
    }

    private function mapTransfer(ShakepayCashRowData $dto): StageTransactionImportData
    {
        if ($dto->debit) {
            $txType = TransactionType::Send;
            $amount = $this->toMoney($dto->debit)->negated();
        } else {
            $txType = TransactionType::Receive;
            $amount = $this->toMoney($dto->credit);
        }

        if ($dto->type == 'Interac e-Transfer') {
            $description = $dto->debit ? $dto->type.' to '.$dto->description : $dto->type.' from '.$dto->description;
        } else {
            if ($dto->type == 'Reward') {
                $description = $dto->description;
            } else {
                $description = $dto->debit ? 'Sent to '.$dto->description : 'Received from '.$dto->description;
            }
        }

        $txDto = new StageTransactionImportData(
            tx_type: $txType,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: $description,
            source: $this->platformName,
            // is_income: $dto->type === 'Reward',
        );

        $txDto->addEntry(new StageEntryImportData(
            tx_at: $txDto->tx_at,
            atomic_type: $amount->isNegative() ? 'out' : 'in',
            wallet_id: $this->wallet->id,
            amount: $amount,
        ));

        return $txDto;
    }
}
