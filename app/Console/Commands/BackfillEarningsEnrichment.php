<?php

namespace App\Console\Commands;

use App\Jobs\EnrichEarningsEvent;
use App\Models\EarningsEvent;
use Illuminate\Console\Command;

/**
 * Re-dispatch the EnrichEarningsEvent job for any EarningsEvent rows that
 * are still missing Company / Exchange / Market Cap. The original
 * enrichment is fired by ScanEarningsSurprises on the next scan, but
 * existing rows in the database don't pick up the new behaviour until
 * they're touched again — this command lets us backfill them on demand.
 */
class BackfillEarningsEnrichment extends Command
{
    protected $signature = 'earnings:enrich-missing
                            {--limit=500 : Max events to enqueue per run}
                            {--sync : Run enrichment synchronously (debug)}';

    protected $description = 'Re-dispatch enrichment for earnings events missing company_name / exchange / market_cap.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $sync = (bool) $this->option('sync');

        $events = EarningsEvent::query()
            ->where(function ($q) {
                $q->whereNull('company_name')
                    ->orWhereNull('exchange')
                    ->orWhereNull('market_cap');
            })
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get(['id']);

        $count = 0;
        foreach ($events as $event) {
            if ($sync) {
                EnrichEarningsEvent::dispatchSync($event->id);
            } else {
                EnrichEarningsEvent::dispatch($event->id);
            }
            $count++;
        }

        $this->info("Dispatched enrichment for {$count} event(s).");

        return self::SUCCESS;
    }
}
