<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class SuperficialLossEvent extends Model
{
    protected $fillable = [
        'acb_event_id',
        'capital_loss_before_denial',
        'denied_loss_amount',
        'allowable_loss_amount',
        'window_start',
        'window_end',
        'reason_code',
        'resolution_type',
        'replacement_acb_event_id',
    ];

    protected $casts = [
        'capital_loss_before_denial' => MoneyCast::class,
        'denied_loss_amount' => MoneyCast::class,
        'allowable_loss_amount' => MoneyCast::class,
        'window_start' => 'immutable_datetime',
        'window_end' => 'immutable_datetime',
    ];
}
