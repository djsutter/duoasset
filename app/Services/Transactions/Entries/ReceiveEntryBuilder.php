<?php

namespace App\Services\Transactions\Entries;

use App\Data\Transactions\BaseTransactionData;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Types\Money;

class ReceiveEntryBuilder implements EntryBuilderInterface
{
    public function buildEntriesArray(BaseTransactionData $dto): array
    {
        $dstWallet = Wallet::findOrFail($dto->dst_wallet_id);
        $srcWallet = app(WalletService::class)->getOrCreateExternalWallet($dstWallet->currency);
        $result = [];

        $result[] = [
            'entry_type' => 'in',
            'wallet_id' => $dstWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->dst_amount, $dto->dst_currency),
        ];

        $result[] = [
            'entry_type' => 'out',
            'wallet_id' => $srcWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->dst_amount, $dto->dst_currency)->negated(),
        ];

        return $result;
    }
}
