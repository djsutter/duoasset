<?php

namespace App\Contracts\Reports;

interface CapitalGainsReportService
{
    public function forAssets(array $assetCodes): self;

    public function forTaxYears(array $years): self;

    /** @return mixed */
    public function build();
}
