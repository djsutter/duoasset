<?php

namespace App\Console\Commands;

use App\Services\Dashboard\DashboardCacheService;
use Illuminate\Console\Command;

class PurgeDashboardCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashboard:cache-purge
                            {--context=all : Dashboard context to purge (all, stocks, crypto, futures)}
                            {--force : Bypass confirmation in non-interactive environments}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Intentionally purge both in-memory versioned dashboard caches and durable last-known-good snapshots';

    public function handle(DashboardCacheService $cacheService): int
    {
        $context = strtolower(trim((string) $this->option('context') ?: 'all'));
        $allowed = ['all', 'stocks', 'crypto', 'futures'];

        if (! in_array($context, $allowed, true)) {
            $this->error("Invalid --context [{$context}]. Allowed: ".implode(', ', $allowed));

            return self::FAILURE;
        }

        $targetContext = $context === 'all' ? null : $context;
        $summary = $cacheService->hardPurge($targetContext);

        $this->info('Dashboard cache and durable last-known-good snapshots purged.');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Target Context', $context],
                ['Contexts Cleared', implode(', ', $summary['contexts'])],
                ['Durable Snapshots Deleted', (string) $summary['durable_deleted']],
            ]
        );

        return self::SUCCESS;
    }
}
