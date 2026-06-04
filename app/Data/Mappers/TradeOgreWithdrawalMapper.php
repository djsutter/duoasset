<?php

namespace App\Data\Mappers;

use App\Data\Imports\StageEntryImportData;
use App\Data\Imports\StageTransactionImportData;
use App\Data\Imports\TradeOgreWithdrawalRowData;
use App\Enums\TransactionType;
use App\Models\StageTransaction;

class TradeOgreWithdrawalMapper extends BaseMapper
{
    protected string $platformName = 'TradeOgre';

    public function map(TradeOgreWithdrawalRowData $dto): ?StageTransactionImportData
    {
        $wallet = $this->walletService->getOrCreateWallet($this->platform, $dto->coin);

        /*
         * The TradeOgre CSV export for withdrawals does not include the withdrawal fee in the amount of the transaction.
         * The fee is listed as a separate line item in the export file, distinct from the withdrawal amount itself.
         * This allows users to accurately account for the fee when calculating taxes or tracking their portfolio,
         * as the fee is a separate cost associated with the withdrawal process.
         *
         * Me: I think that summary is wrong. I looked at a trade on TO on 2021-04-10 where I bought ARRR. The next
         * day I withdrew the full amount, and that is what is shown as the amount in the withdrawal CSV. It must
         * include the fee, because otherwise I would be overdrawing the account.
         */

        $amount = $this->toMoney($dto->amount, $dto->coin)->negated();
        $fee = $this->toMoney($dto->fee, $dto->coin)->negated();

        $txDto = new StageTransactionImportData(
            tx_type: TransactionType::Send,
            tx_at: $this->parseDate($dto->date),
            status: StageTransaction::STATUS_UNMATCHED,
            description: "Withdraw $dto->coin from $this->platformName",
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
