<?php

namespace App\Console\Commands;

use App\Services\Watchlists\DefaultWatchlistService;
use Illuminate\Console\Command;

class BackfillDefaultWatchlistsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watchlists:backfill-default
                            {--user= : Backfill specific user ID}
                            {--fresh : Force fresh resolution of stock recommendations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently backfill default watchlists and recommendations for subscribers without modifying populated user-managed watchlists';

    public function handle(DefaultWatchlistService $service): int
    {
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $fresh = (bool) $this->option('fresh');

        $this->info('Starting default watchlist backfill...');

        try {
            $stats = $service->backfillExistingUsers($userId, $fresh);
        } catch (\Throwable $e) {
            $this->error("Backfill execution failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Users Processed', $stats['total']],
                ['Default Watchlists Created', $stats['created']],
                ['Empty Watchlists Populated', $stats['populated']],
                ['User Watchlists Preserved / Skipped', $stats['skipped']],
                ['Errors / Failures', $stats['failed']],
            ]
        );

        if ($stats['failed'] > 0) {
            $this->warn("Completed with {$stats['failed']} failure(s). Check application logs for details.");

            return self::FAILURE;
        }

        $this->info('Default watchlist backfill completed successfully.');

        return self::SUCCESS;
    }
}
