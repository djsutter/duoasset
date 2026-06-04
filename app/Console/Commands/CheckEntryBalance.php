<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class CheckEntryBalance extends Command
{
    protected $signature = 'entries:check-balance {threshold=10 : Percentage difference allowed}';

    protected $description = 'Check transactions where IN vs OUT amounts differ by more than a given percentage';

    public function handle()
    {
        $threshold = (float) $this->argument('threshold'); // % difference allowed
        $count = 0;

        // Load all transactions with their entries (in chunks if needed)
        Transaction::with('entries')->chunk(500, function ($transactions) use ($threshold, &$count) {

            foreach ($transactions as $transaction) {

                // Filter entries: only keep in/out, ignore fee
                $in = $transaction->entries->firstWhere('entry_type', 'in');
                $out = $transaction->entries->firstWhere('entry_type', 'out');

                if (! $in || ! $out) {
                    // Missing required entries — skip
                    continue;
                }

                if (is_null($in->amount) || is_null($out->amount)) {
                    $this->warn("Transaction {$transaction->id}: has null amount(s) — cannot compare.");

                    continue;
                }

                // Extract numeric values from Money
                $inValue = abs((float) $in->amount->toDecimal());
                $outValue = abs((float) $out->amount->toDecimal());

                if ($outValue == 0.0) {
                    $this->warn("Transaction {$transaction->id}: OUT amount is zero — cannot compare.");

                    continue;
                }

                // % difference = (in - out) / out * 100
                $percentDiff = (($inValue - $outValue) / $outValue) * 100;
                // echo "$inValue $outValue $percentDiff%\n";

                if (abs($percentDiff) > $threshold) {

                    $count++;

                    $this->info(sprintf(
                        'Transaction %d: IN=%s OUT=%s Diff=%.2f%% in-wallet=%d out-wallet=%d date=%s',
                        $transaction->id,
                        $in->amount->toDecimal(),
                        $out->amount->toDecimal(),
                        $percentDiff,
                        $in->wallet_id,
                        $out->wallet_id,
                        $transaction->transaction_at
                    ));
                }
            }
        });

        $this->line("\nDone. Found {$count} mismatched transactions.\n");

        return Command::SUCCESS;
    }
}
