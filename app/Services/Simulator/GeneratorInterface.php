<?php

namespace App\Services\Simulator;

use Carbon\Carbon;
use Illuminate\Support\Collection;

interface GeneratorInterface
{
    /**
     * Generate synthetic transactions between $start and $end for a given profile
     * return Illuminate\Support\Collection of SyntheticTransaction
     */
    public function generate(Carbon $start, Carbon $end, array $context = []): Collection;
}
