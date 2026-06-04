<?php

namespace App\Services\Tax;

use App\Data\Tax\Schedule3\Schedule3BuilderInterface;
use App\Enums\Schedule3Method;

final class Schedule3BuilderResolver
{
    public function __construct(
        private LotBasedSchedule3Builder $lotBuilder,
        private PoolBasedSchedule3Builder $poolBuilder,
    ) {}

    public function resolve(Schedule3Method $method): Schedule3BuilderInterface
    {
        return match ($method) {
            Schedule3Method::Lot => $this->lotBuilder,
            Schedule3Method::Pool => $this->poolBuilder,
            default => throw new \InvalidArgumentException("Unknown Schedule3 method [{$method->value}]"),
        };
    }
}
