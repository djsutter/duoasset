<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\WalletBalanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class ValuateImportedTransactions implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $abs = app(WalletBalanceService::class);
        foreach (Wallet::all() as $a) {
            $abs->calculateBalance($a);
        }

        Transaction::where('valuation_status', 'pending')
            ->orderBy('id')
            ->chunk(100, function ($chunk) {
                ValuateChunk::dispatch($chunk->pluck('id')->toArray());
            });
    }
}
