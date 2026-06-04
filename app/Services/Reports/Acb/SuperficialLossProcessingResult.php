<?php

namespace App\Services\Reports\Acb;

class SuperficialLossProcessingResult
{
    public function __construct(
        public readonly AssetAcbAuditLedgerResult $ledger,
        public readonly array $deniedLossByRow,
    ) {}
}
