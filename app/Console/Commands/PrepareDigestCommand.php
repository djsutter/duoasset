<?php

namespace App\Console\Commands;

use App\Services\Notifications\ScannerDigestCoordinator;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PrepareDigestCommand extends Command
{
    protected $signature = 'digest:prepare
                            {category : Digest category (setup_scoring or eps_surprise)}
                            {--run-id= : Explicit scan_run_id to finalize and prepare}
                            {--sync : Execute digest preparation and per-user delivery synchronously}';

    protected $description = 'Complete an active scanner digest collection session and prepare the consolidated digest email';

    public function handle(ScannerDigestCoordinator $coordinator): int
    {
        $rawCategory = (string) $this->argument('category');

        try {
            $category = $coordinator->normalizeCategory($rawCategory);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $explicitRunId = $this->option('run-id');
        $runId = is_numeric($explicitRunId) ? (int) $explicitRunId : null;
        $sync = (bool) $this->option('sync');

        $preparedRunId = $coordinator->prepareDigest($category, $runId, $sync);

        if ($preparedRunId === null) {
            $this->warn("No active collecting scan run found for [{$category}].");

            return self::SUCCESS;
        }

        $this->info("Finalized collection and dispatched consolidated digest for [{$category}] (Run #{$preparedRunId}).");

        return self::SUCCESS;
    }
}
