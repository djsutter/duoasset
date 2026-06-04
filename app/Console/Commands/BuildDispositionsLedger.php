<?php

namespace App\Console\Commands;

use App\Services\Tax\TaxPoolBuilderService;
use Illuminate\Console\Command;

class BuildDispositionsLedger extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tax:build-dispositions-ledger';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Builds the dispositions from the database';

    /**
     * Execute the console command.
     */
    public function handle(TaxPoolBuilderService $service)
    {
        $service->rebuild();

        $this->info('Dispositions ledger rebuild complete.');

        return self::SUCCESS;
    }
}
