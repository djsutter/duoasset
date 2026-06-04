<?php

namespace App\Data\Csv;

use App\Models\WalletEntry;
use Carbon\Carbon;

final class WalletEntryCsvMapper
{
    /**
     * Export: Model → DTO
     */
    public static function fromModel(WalletEntry $entry): WalletEntryCsvData
    {
        return new WalletEntryCsvData(
            transaction_at: $entry->transaction_at->toIso8601ZuluString(),
            source_transaction_id: $entry->transaction_id, // renamed semantically
            wallet_id: (string) $entry->wallet_id,
            entry_type: $entry->entry_type,
            amount: $entry->amount->format(),
            currency: strtoupper($entry->currency),
            foreign_amount: $entry->foreign_amount?->format(),
            foreign_currency: $entry->foreign_currency,
            fee_amount: null,       // if/when modeled
            fee_currency: null,
            description: null,
        );
    }

    /**
     * Import: DTO → array payload for model creation
     * (intentionally NOT returning a model to avoid side effects)
     */
    public static function toModelAttributes(WalletEntryCsvData $data): array
    {
        return [
            'transaction_at' => Carbon::parse($data->transaction_at),
            'transaction_id' => $data->source_transaction_id, // advisory
            'wallet_id' => $data->wallet_id,
            'entry_type' => self::normalizeEntryType($data->entry_type),
            'amount' => self::normalizeAmount($data->amount),
            'currency' => strtoupper($data->currency),
            'foreign_amount' => $data->foreign_amount,
            'foreign_currency' => $data->foreign_currency,
        ];
    }

    /**
     * Import helper: from raw CSV row → DTO
     */
    public static function fromCsvRow(array $row): WalletEntryCsvData
    {
        // Assumes row already aligned with headers order
        return new WalletEntryCsvData(
            transaction_at: $row[0],
            source_transaction_id: $row[1] ?: null,
            wallet_id: $row[2],
            entry_type: $row[3],
            amount: $row[4],
            currency: $row[5],
            foreign_amount: $row[6] ?: null,
            foreign_currency: $row[7] ?: null,
            fee_amount: $row[8] ?: null,
            fee_currency: $row[9] ?: null,
            description: $row[10] ?: null,
        );
    }

    /**
     * Optional normalization rules
     */
    private static function normalizeEntryType(string $type): string
    {
        return strtolower(trim($type));
    }

    private static function normalizeAmount(string $amount): string
    {
        // strip whitespace, enforce decimal string
        return trim($amount);
    }
}
