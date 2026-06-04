<?php

// -----------------------------------------------------------------------------
// Console command — emits JSON lines to stdout or writes to file
// Put this class under app/Console/Commands/SimulateCrypto.php
// -----------------------------------------------------------------------------

namespace App\Console\Commands;

use App\Services\Simulator\DcaGenerator;
use App\Services\Simulator\PriceService;
use App\Services\Simulator\SimulatorService;
use App\Services\Simulator\SwingGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SimulateCrypto extends Command
{
    protected $signature = 'simulate:crypto {start?} {end?} {--out=output.jsonl}';

    protected $description = 'Generate synthetic crypto transactions for demo/testing';

    public function handle()
    {
        $start = Carbon::parse($this->argument('start') ?? now()->subYears(3)->startOfDay());
        $end = Carbon::parse($this->argument('end') ?? now()->endOfDay());

        $price = new PriceService;

        $sim = new SimulatorService;
        $sim->addGenerator(new DcaGenerator($price, 'BTC', 200.0, 'wallet:duncan', 'weekly'));
        $sim->addGenerator(new DcaGenerator($price, 'ETH', 100.0, 'wallet:duncan', 'monthly'));
        $sim->addGenerator(new SwingGenerator($price, 'BTC', 1500.0, 'wallet:trader'));

        $events = $sim->run($start, $end);

        $outPath = $this->option('out');
        $fh = fopen($outPath, 'w');
        foreach ($events as $ev) {
            fwrite($fh, json_encode($ev->toArray())."\n");
        }
        fclose($fh);

        $this->info('Wrote '.$events->count()." transactions to {$outPath}\n");

        return 0;
    }
}
