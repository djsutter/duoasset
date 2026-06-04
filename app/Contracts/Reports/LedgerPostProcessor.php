<?php

namespace App\Contracts\Reports;

use App\Services\Reports\Acb\AssetAcbAuditLedgerResult;
use App\Services\Reports\Acb\SuperficialLossProcessingResult;

interface LedgerPostProcessor
{
    public function process(AssetAcbAuditLedgerResult $result): SuperficialLossProcessingResult;
}
