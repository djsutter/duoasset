<?php

namespace App\Services\Tax;

use App\Data\Tax\Schedule3\Schedule3Data;
use App\Tax\Application\Schedule3MethodResolver;

final class TaxService
{
    public function __construct(
        private Schedule3BuilderResolver $resolver,
    ) {}

    public function buildSchedule3(int $taxYear): Schedule3Data
    {
        $method = app(Schedule3MethodResolver::class)->resolve();
        $builder = $this->resolver->resolve($method);

        return $builder->build($taxYear);
    }
}
