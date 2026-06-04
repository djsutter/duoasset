<?php

namespace App\Domain\Tax\Continuity;

use App\Types\AssetQuantity;
use App\Types\Money;

final class AssetActivitySnapshot
{
    public function __construct(
        public readonly AssetQuantity $quantityAcquired,
        public readonly Money $acbAdded,

        public readonly AssetQuantity $quantityDisposed,
        public readonly Money $proceeds,
        public readonly Money $acbOfDispositions,

        public readonly Money $realizedGainBeforeDenial,
        public readonly Money $deniedLoss,

        public readonly Money $acbReportable,
        public readonly Money $gainOrLoss,
    ) {}
}
