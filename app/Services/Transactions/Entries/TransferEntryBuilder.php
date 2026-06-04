<?php

namespace App\Services\Transactions\Entries;

use App\Data\Transactions\BaseTransactionData;
use App\Models\Wallet;
use App\Types\Money;

class TransferEntryBuilder implements EntryBuilderInterface
{
    public function buildEntriesArray(BaseTransactionData $dto): array
    {
        $srcWallet = Wallet::findOrFail($dto->src_wallet_id);
        $dstWallet = Wallet::findOrFail($dto->dst_wallet_id);
        $result = [];

        $result[] = [
            'entry_type' => 'out',
            'wallet_id' => $srcWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->src_amount, $dto->src_currency)->negated(),
        ];

        $result[] = [
            'entry_type' => 'in',
            'wallet_id' => $dstWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->src_amount, $dto->src_currency),
        ];

        if ($dto->fee_amount && $dto->fee_currency) {
            $result[] = [
                'entry_type' => 'fee',
                'wallet_id' => $dto->fee_currency == $dto->src_currency ? $srcWallet->id : $dstWallet->id,
                'foreign_amount' => Money::fromDecimal($dto->fee_amount, $dto->fee_currency)->negated(),
            ];
        }

        return $result;
    }
}
