<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LotDisposition extends Model
{
    protected $fillable = [
        'lot_id',
        'asset_code',
        'disposed_quantity',
        'proceeds',
        'proceeds_currency',
        'acb_allocated',
        'denied_loss_amount',
        'disposed_at',
        'acb_event_id',
    ];

    protected $casts = [
        'disposed_quantity' => AssetQuantityCast::class,
        'proceeds' => MoneyCast::class.':proceeds_currency',
        'acb_allocated' => MoneyCast::class,
        'denied_loss_amount' => MoneyCast::class,
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
