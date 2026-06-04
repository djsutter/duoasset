<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use App\Enums\AcbEventType;
use Illuminate\Database\Eloquent\Model;

class TaxPoolDisposition extends Model
{
    protected $fillable = [
        'acb_event_id',
        'origin_event_type',
        'asset_code',
        'currency',
        'quantity_disposed',
        'proceeds',
        'acb_allocated',
        'capital_gain_loss_before_denial',
        'denied_loss_amount',
        'capital_gain_loss_after_denial',
        'disposition_date',
    ];

    protected $casts = [
        'origin_event_type' => AcbEventType::class,
        'quantity_disposed' => AssetQuantityCast::class,
        'proceeds' => MoneyCast::class,
        'acb_allocated' => MoneyCast::class,
        'capital_gain_loss_before_denial' => MoneyCast::class,
        'denied_loss_amount' => MoneyCast::class,
        'capital_gain_loss_after_denial' => MoneyCast::class,
        'disposition_date' => 'immutable_date',
    ];
}
