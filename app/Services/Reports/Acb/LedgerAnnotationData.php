<?php

namespace App\Services\Reports\Acb;

class LedgerAnnotationData
{
    public function __construct(
        public readonly string $message
    ) {}
}
