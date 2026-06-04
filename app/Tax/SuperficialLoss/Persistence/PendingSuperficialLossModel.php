<?php

namespace App\Tax\SuperficialLoss\Persistence;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

final class PendingSuperficialLossModel extends Model
{
    protected $table = 'pending_superficial_losses';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'asset_code',
        'currency',
        'acb_event_id',
        'window_start',
        'window_end',
        'original_loss_amount',
        'original_units',
        'remaining_loss_amount',
        'remaining_units',
        'status',
        'expired_at',
    ];

    protected $casts = [
        'window_start' => 'immutable_datetime',
        'window_end' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'original_loss_amount' => MoneyCast::class,
        'remaining_loss_amount' => MoneyCast::class,
        'original_units' => AssetQuantityCast::class,
        'remaining_units' => AssetQuantityCast::class,
    ];

    // -----------------------------
    // Domain ↔ Persistence mapping
    // -----------------------------
    public function toDomain(): PendingSuperficialLoss
    {
        return PendingSuperficialLoss::rehydrate(
            id: Uuid::fromString($this->id),
            assetCode: $this->asset_code,
            acbEventId: $this->acb_event_id,
            windowStart: $this->window_start,
            windowEnd: $this->window_end,
            originalLossAmount: $this->original_loss_amount,
            originalUnits: $this->original_units,
            remainingLossAmount: $this->remaining_loss_amount,
            remainingUnits: $this->remaining_units,
        );
    }

    public static function fromDomain(PendingSuperficialLoss $loss): self
    {
        return new self([
            'id' => $loss->id->toString(),
            'asset_code' => $loss->assetCode,
            'currency' => $loss->originalLossAmount->currency,
            'acb_event_id' => $loss->acbEventId,
            'window_start' => $loss->windowStart,
            'window_end' => $loss->windowEnd,
            'original_loss_amount' => $loss->originalLossAmount,
            'original_units' => $loss->originalUnits,
            'remaining_loss_amount' => $loss->remainingLossAmount,
            'remaining_units' => $loss->remainingUnits,
            'status' => $loss->status()->value,
            'expired_at' => $loss->expiredAt?->toDateTimeString(),
        ]);
    }
}
