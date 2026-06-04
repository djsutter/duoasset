<?php

namespace App\Traits;

use App\Models\Transaction;
use App\Models\WalletEntry;

trait BuildsEntries
{
    protected function buildEntries(Transaction $tx, array $entries): void
    {
        foreach ($entries as $entry) {
            WalletEntry::create([
                'transaction_id' => $tx->id,
                'entry_type' => $entry['entry_type'],
                'wallet_id' => $entry['wallet_id'],
                'foreign_amount' => $entry['foreign_amount'],
            ]);
        }
    }

    protected function replaceEntries(Transaction $tx, array $entries): void
    {
        $tx->entries()->delete();
        $this->buildEntries($tx, $entries);
    }
}
