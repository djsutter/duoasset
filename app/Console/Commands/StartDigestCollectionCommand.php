<?php

namespace App\Console\Commands;

use App\Services\Notifications\ScannerDigestCoordinator;
use Illuminate\Console\Command;
use InvalidArgumentException;

class StartDigestCollectionCommand extends Command
{
    protected $signature = 'digest:start-collection
                            {category : Digest category (setup_scoring or eps_surprise)}
                            {--from= : Optional start date for earnings scan run}
                            {--to= : Optional end date for earnings scan run}';

    protected $description = 'Start a coordinated scanner digest collection session so multi-step scans emit a single consolidated digest';

    public function handle(ScannerDigestCoordinator $coordinator): int
    {
        $rawCategory = (string) $this->argument('category');

        try {
            $category = $coordinator->normalizeCategory($rawCategory);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Clear any leftover unclosed session before starting a fresh nightly collection
        $coordinator->clearActiveRunId($category);

        $run = $coordinator->startCollecting($category, array_filter([
            'from_date' => $this->option('from'),
            'to_date' => $this->option('to'),
        ], fn ($v) => $v !== null && $v !== ''));

        $this->info("Started coordinated digest collection for [{$category}] (Run #{$run->id}).");

        return self::SUCCESS;
    }
}
