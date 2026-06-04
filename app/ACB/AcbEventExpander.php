<?php

namespace App\ACB;

use App\Data\ACB\AcbEventData;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\WalletEntry;

class AcbEventExpander
{
    /** @return list<AcbEventData> */
    public static function fromTransaction(Transaction $tx): array
    {
        if (! $tx->isValued()) {
            return [];
        }

        return match ($tx->tx_type) {
            TransactionType::Trade => self::expandTrade($tx),
            TransactionType::Send => self::expandSend($tx),
            TransactionType::Receive => self::expandReceive($tx),
            TransactionType::Transfer => self::expandTransfer($tx),
            default => [],
        };
    }

    /** @return list<AcbEventData> */
    private static function expandTrade(Transaction $tx): array
    {
        $entries = self::groupInternalEntries($tx);

        $applyToOut = false;
        $applyToIn = false;
        $fee = $entries['fee'] ?? null;

        if ($fee) {
            $matchesOut = $fee->foreign_currency === $entries['out']?->foreign_currency;
            $matchesIn = $fee->foreign_currency === $entries['in']?->foreign_currency;

            if ($matchesOut && ! $matchesIn) {
                $applyToOut = true;
            } elseif ($matchesIn && ! $matchesOut) {
                $applyToIn = true;
            } elseif ($matchesOut && $matchesIn) {
                // Same currency both sides → apply to OUT only
                $applyToOut = true;
            }
        }

        return array_values(array_filter([
            isset($entries['in'])
                ? AcbEventData::acquisition($tx, $entries['in'], $applyToIn ? $fee : null)
                : null,

            isset($entries['out'])
                ? AcbEventData::disposal($tx, $entries['out'], $applyToOut ? $fee : null)
                : null,
        ]));
    }

    /** @return list<AcbEventData> */
    private static function expandSend(Transaction $tx): array
    {
        $entries = self::groupInternalEntries($tx);

        if (! isset($entries['out'])) {
            return [];
        }

        return [
            AcbEventData::disposal(
                $tx,
                $entries['out'],
                $entries['fee'] ?? null
            ),
        ];
    }

    /** @return list<AcbEventData> */
    private static function expandReceive(Transaction $tx): array
    {
        $entries = self::groupInternalEntries($tx);

        if (! isset($entries['in'])) {
            return [];
        }

        return [
            AcbEventData::acquisition(
                $tx,
                $entries['in'],
                $entries['fee'] ?? null
            ),
        ];
    }

    /** @return list<AcbEventData> */
    private static function expandTransfer(Transaction $tx): array
    {
        $entries = self::groupInternalEntries($tx);

        // A transfer to an external wallet is a disposal
        if ($entries['out']?->wallet->isExternal()) {
            return [
                AcbEventData::disposal(
                    $tx,
                    $entries['out'],
                    $entries['fee'] ?? null
                ),
            ];
        }

        if (! isset($entries['fee'])) {
            return [];
        }

        return [
            AcbEventData::transferFeeFromEntry($entries['fee']),
        ];
    }

    /** @return array<string, WalletEntry> */
    private static function groupInternalEntries(Transaction $tx): array
    {
        return $tx->entries
            ->reject(fn ($e) => $e->wallet->isExternal() || $e->wallet->isLiability())
            ->keyBy('entry_type')
            ->all();
    }
}
