<?php

namespace App\Services;

use App\Data\Csv\WalletEntryCsvData;
use App\Data\Csv\WalletEntryCsvMapper;
use App\Models\Wallet;
use League\Csv\Writer;

class WalletCsvService
{
    public function exportWallet(Wallet $wallet): void
    {
        $entries = $wallet->entries()->orderBy('transaction_at')->get();
        $path = storage_path("app/{$wallet->slug()}_entries.csv");
        $csv = Writer::from($path, 'w');
        $csv->insertOne(WalletEntryCsvData::headers());
        foreach ($entries as $entry) {
            $csv->insertOne(WalletEntryCsvMapper::fromModel($entry)->toArray());
        }
    }
}
