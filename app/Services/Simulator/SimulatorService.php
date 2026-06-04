<?php

namespace App\Services\Simulator;

// -----------------------------------------------------------------------------
// Simulator service — coordinates generators and outputs ordered events
// -----------------------------------------------------------------------------
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SimulatorService
{
    protected array $generators = [];

    public function addGenerator(GeneratorInterface $g): self
    {
        $this->generators[] = $g;

        return $this;
    }

    /**
     * Generate combined events and return sorted collection
     */
    public function run(Carbon $start, Carbon $end): Collection
    {
        $all = collect();
        foreach ($this->generators as $g) {
            $events = $g->generate($start, $end);
            $all = $all->merge($events);
        }

        // sort by timestamp and return
        return $all->sortBy(function (SyntheticTransaction $tx) {
            return $tx->timestamp->getTimestamp();
        })->values();
    }
}
