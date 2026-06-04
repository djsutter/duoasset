<?php

namespace App\Console\Commands;

use App\Models\AcbDaily;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Models\Transaction;
use App\Services\Acb\AcbDailyGenerator;
use App\Services\Acb\AcbEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildAcbCommand extends Command
{
    protected $signature = 'acb:rebuild
                            {--asset= : Only rebuild for a single asset code (eg. BTC)}
                            {--fresh : Truncate acb_events and acb_dailies before building}
                            {--since= : Only process transactions on/after this date (Y-m-d)}';

    protected $description = 'Build ACB events and daily snapshots from transactions';

    public function handle()
    {
        $assetFilter = strtoupper($this->option('asset'));
        $fresh = $this->option('fresh');
        $since = $this->option('since') ? \Carbon\Carbon::parse($this->option('since')) : null;

        // Not even an option anymore.
        $fresh = true;

        if ($fresh) {
            $this->info('Truncating acb_events, acb_dailies and assets...');
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            AcbEvent::truncate();
            AcbDaily::truncate();
            Asset::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $engine = new AcbEngine($this->output);

        $query = Transaction::orderBy('transaction_at');

        if ($since) {
            $query->where('transaction_at', '>=', $since->startOfDay());
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No transactions found.');

            return 0;
        }

        $this->info("Processing {$total} transactions...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Process in chunks to avoid memory issues
        $query->chunk(200, function ($txs) use ($bar, $engine, $assetFilter) {
            foreach ($txs as $tx) {
                // Optionally skip transactions that don't touch the requested asset
                if ($assetFilter) {
                    $hasAsset = false;
                    foreach ($tx->entries as $entry) {
                        if ($entry->foreign_currency === $assetFilter || $entry->currency === $assetFilter) {
                            $hasAsset = true;
                            break;
                        }
                    }
                    if (! $hasAsset) {
                        $bar->advance();

                        continue;
                    }
                }

                $engine->generateForTransaction($tx, $assetFilter);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');
        $this->info('ACB events built.');

        $this->info('Generating daily snapshots...');
        $generator = new AcbDailyGenerator;
        if ($assetFilter) {
            // try to find asset id for the code
            $asset = Asset::where('asset_code', $assetFilter)->first();
            if ($asset) {
                $generator->buildForAsset($asset->asset_code);
            } else {
                $this->warn("No Asset record for {$assetFilter}; skipping snapshot generation.");
            }
        } else {
            $generator->buildAll();
        }

        $this->info('ACB daily snapshots complete.');

        return 0;
    }
}
