<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\Import\ValuationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class ValuateChunk implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public array $transactionIds) {}

    public function handle(ValuationService $valuationService): void
    {
        $transactions = Transaction::whereIn('id', $this->transactionIds)->get();

        foreach ($transactions as $tx) {
            $valuationService->valuate($tx);
        }
    }
}
