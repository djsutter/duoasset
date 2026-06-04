<?php

namespace App\Domain\Tax\Continuity\Data;

use App\Domain\Tax\Continuity\AssetActivitySnapshot;
use App\Domain\Tax\Continuity\AssetStateSnapshot;
use App\Types\AssetQuantity;
use App\Types\Money;

final class AssetContinuityData
{
    public function __construct(
        public readonly string $asset_code,

        // Opening
        public readonly AssetQuantity $opening_quantity,
        public readonly Money $opening_acb,

        // Activity
        public readonly AssetQuantity $quantity_acquired,
        public readonly Money $acb_added,

        public readonly AssetQuantity $quantity_disposed,
        public readonly Money $proceeds,
        public readonly Money $acb_of_dispositions,
        public readonly Money $realized_gain_before_denial,
        public readonly Money $denied_loss,
        public readonly Money $gain_or_loss,

        // Closing
        public readonly AssetQuantity $closing_quantity,
        public readonly Money $closing_acb,
    ) {}

    public static function fromSnapshots(
        string $assetCode,
        AssetStateSnapshot $opening,
        AssetActivitySnapshot $activity,
        AssetStateSnapshot $closing,
    ): self {
        return new self(
            asset_code: $assetCode,

            opening_quantity: $opening->quantity,
            opening_acb: $opening->acb,

            quantity_acquired: $activity->quantityAcquired,
            acb_added: $activity->acbAdded,

            quantity_disposed: $activity->quantityDisposed,
            proceeds: $activity->proceeds,
            acb_of_dispositions: $activity->acbReportable,
            realized_gain_before_denial: $activity->realizedGainBeforeDenial,
            denied_loss: $activity->deniedLoss,
            gain_or_loss: $activity->gainOrLoss,

            closing_quantity: $closing->quantity,
            closing_acb: $closing->acb,
        );
    }
}
