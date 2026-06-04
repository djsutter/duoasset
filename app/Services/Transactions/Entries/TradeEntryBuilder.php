<?php

namespace App\Services\Transactions\Entries;

use App\Data\Transactions\BaseTransactionData;
use App\Models\Platform;
use App\Services\WalletService;
use App\Types\Money;

class TradeEntryBuilder implements EntryBuilderInterface
{
    public function buildEntriesArray(BaseTransactionData $dto): array
    {
        $walletService = app(WalletService::class);
        $platform = Platform::findOrFail($dto->platform_id);
        $srcWallet = $walletService->getWallet($platform, $dto->src_currency);
        $dstWallet = $walletService->getWallet($platform, $dto->dst_currency);
        $result = [];

        $result[] = [
            'entry_type' => 'out',
            'wallet_id' => $srcWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->src_amount, $dto->src_currency)->negated(),
        ];

        $result[] = [
            'entry_type' => 'in',
            'wallet_id' => $dstWallet->id,
            'foreign_amount' => Money::fromDecimal($dto->dst_amount, $dto->dst_currency),
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
