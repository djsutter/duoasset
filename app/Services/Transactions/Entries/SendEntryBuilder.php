<?php

namespace App\Services\Transactions\Entries;

use App\Data\Transactions\BaseTransactionData;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Types\Money;

class SendEntryBuilder implements EntryBuilderInterface
{
    public function buildEntriesArray(BaseTransactionData $dto): array
    {
        $srcWallet = Wallet::findOrFail($dto->src_wallet_id);
        $srcCurrency = $srcWallet->currency;
        $dstWallet = app(WalletService::class)->getOrCreateExternalWallet($srcCurrency);
        $result = [];

        $result[] = [
            'entry_type' => 'out',
            'wallet_id' => $srcWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->src_amount, $srcCurrency)->negated(),
        ];

        $result[] = [
            'entry_type' => 'in',
            'wallet_id' => $dstWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->src_amount, $srcCurrency),
        ];

        if ($dto->fee_amount && $dto->fee_currency) {
            $result[] = [
                'entry_type' => 'fee',
                'wallet_id' => $dto->fee_currency == $srcCurrency ? $srcWallet->id : $dstWallet->id,
                'foreign_amount' => Money::fromDecimal($dto->fee_amount, $dto->fee_currency)->negated(),
            ];
        }

        return $result;
    }
}
