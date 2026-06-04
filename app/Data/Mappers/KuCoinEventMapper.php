<?php

namespace App\Data\Mappers;

use App\Data\Imports\KuCoinEventData;
use App\Data\Imports\KuCoinRowData;
use Illuminate\Support\Collection;

class KuCoinEventMapper extends BaseMapper
{
    protected string $platformName = 'KuCoin';

    public string $importType = '';

    /**
     * Map all rows from KuCoin funding history and merge similar ones.
     *
     * @param  Collection<int, KuCoinRowData>  $rows
     * @return Collection<int, KuCoinEventData>
     */
    public function mapAll(Collection $rows, string $importType): Collection
    {
        $this->importType = $importType;

        // Step 1: Group rows that share the same tx id, type, currency, and timestamp
        $grouped = $rows->groupBy(fn (KuCoinRowData $dto) => implode('|', [
            $dto->type,
            $dto->side,
            $dto->currency,
            $this->parseDate($dto->time)->format('Y-m-d H:i:s'),
        ]));

        // Step 2: Merge each group into a single combined transaction Data object
        return $grouped->map(fn (Collection $group) => $this->mapGroup($group));
    }

    /**
     * Map a single merged group of similar rows.
     */
    private function mapGroup(Collection $group): ?KuCoinEventData
    {
        /** @var KuCoinRowData $first */
        $first = $group->first();

        // Combine numeric values
        /** @var string $totalAmount */
        $totalAmount = $group->reduce(
            fn ($carry, $dto) => bcadd($carry, $dto->amount, 14),
            '0'
        );
        /** @var string $totalFee */
        $totalFee = $group->reduce(
            fn ($carry, $dto) => bcadd($carry, $dto->fee ?? '0', 14),
            '0'
        );

        // Clone a representative data object with combined totals
        $combined = clone $first;
        $combined->amount = $totalAmount;
        $combined->fee = $totalFee;

        return $this->mapRow($combined);
    }

    private function mapRow(KuCoinRowData $dto): ?KuCoinEventData
    {
        if ($dto->type == 'Interest') {
            return null; // For now. Interest was my invention.
        }

        $accountType = $this->importType;
        $amount = $this->toDecimal($dto->amount);
        $fee = $dto->fee ? $this->toDecimal($dto->fee) : null;

        switch ($dto->type) {
            case 'Transfer':
                $eventType = 'transfer';
                break;
            case 'Borrowings':
                $eventType = 'borrow';
                $accountType = 'Margin';
                $dto->side = 'Deposit';
                break;
            case 'Cross Margin Trading':
                $eventType = 'trade';
                if ($fee && $dto->side == 'Withdrawal') {
                    $amount = bcsub($amount, $fee, 14);
                } else {
                    $amount = bcadd($amount, $fee, 14);
                }
                break;
            case 'Debt Repayment':
                $eventType = 'repayment';
                $accountType = 'Margin';
                $dto->side = 'Withdrawal';
                break;
            case 'Deposit':
                $eventType = 'deposit';
                break;
            case 'Withdraw':
                $eventType = 'withdraw';
                if ($fee) {
                    $amount = bcsub($amount, $fee, 14);
                }
                break;
            case 'Spot':
                $eventType = 'trade';
                if ($dto->side == 'Withdrawal') {
                    $fee = null;
                }
                if ($fee) {
                    $amount = bcadd($amount, $fee, 14);
                }
        }

        return new KuCoinEventData(
            time: $dto->time,
            event_type: $eventType,
            side: $dto->side,
            currency: $dto->currency,
            amount: $amount,
            fee: $fee,
            account_type: $accountType,
        );
    }

    private function toDecimal(string $amount): string
    {
        return preg_replace('/^(?:0+(?=\d))|(?:(?<=\.\d)0+)$/', '', $amount);
    }
}
