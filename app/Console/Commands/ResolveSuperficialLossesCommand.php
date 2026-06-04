<?php

namespace App\Console\Commands;

use App\Tax\Events\AcquisitionEventRepository;
use App\Tax\SuperficialLoss\Application\ResolveSuperficialLosses;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ResolveSuperficialLossesCommand extends Command
{
    protected $signature = 'tax:resolve-superficial-losses';

    protected $description = 'Resolve CRA superficial losses';

    public function handle(
        ResolveSuperficialLosses $service,
        AcquisitionEventRepository $acquisitions
    ): int {
        $service->run(
            acquisitions: $acquisitions->recent()->all(),
            asOf: CarbonImmutable::now()
        );

        return self::SUCCESS;
    }
}
