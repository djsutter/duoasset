<?php

namespace App\Domain\Tax\Continuity;

use Carbon\Carbon;

interface TaxAssetStateBuilderInterface
{
    public function buildUpToDate(
        string $assetCode,
        Carbon $date
    ): AssetStateSnapshot;

    public function buildBetweenDates(
        string $assetCode,
        Carbon $start,
        Carbon $end
    ): AssetActivitySnapshot;

    /**
     * @return string[]
     */
    public function getActiveAssets(): array;
}
