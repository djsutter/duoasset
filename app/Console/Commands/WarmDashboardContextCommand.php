<?php

namespace App\Console\Commands;

use App\Services\Dashboard\DashboardCacheService;
use Illuminate\Console\Command;

class WarmDashboardContextCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashboard:warm-context
                            {context=stocks : Dashboard context to warm (all, stocks, crypto, futures)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-compute and warm active dashboard context snapshots';

    public function handle(DashboardCacheService $cacheService): int
    {
        $context = strtolower(trim((string) $this->argument('context') ?: 'stocks'));
        $allowed = ['all', 'stocks', 'crypto', 'futures'];

        if (! in_array($context, $allowed, true)) {
            $this->error("Invalid context [{$context}]. Allowed: ".implode(', ', $allowed));

            return self::FAILURE;
        }

        $contextsToWarm = $context === 'all' ? ['stocks', 'crypto', 'futures'] : [$context];

        foreach ($contextsToWarm as $ctx) {
            $this->info("Warming dashboard snapshot for [{$ctx}]...");
            $cacheService->warmContextSnapshot($ctx);
        }

        $this->info('Dashboard snapshots successfully warmed.');

        return self::SUCCESS;
    }
}
