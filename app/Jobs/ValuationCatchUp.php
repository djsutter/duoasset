<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValuationCatchUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Transaction::where('valuation_status', 'pending')
            ->orderBy('id')
            ->chunk(100, function ($chunk) {
                ValuateChunk::dispatch($chunk->pluck('id')->toArray());
            });
    }
}
