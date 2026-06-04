<?php

namespace App\Services\Reports\Acb;

final readonly class CapitalGainsOptions
{
    public function __construct(
        public bool $applySuperficialLoss = false,
        public bool $explainSuperficialLoss = false,
    ) {}
}
