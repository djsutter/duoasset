<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use Illuminate\Database\Eloquent\Model;

class PooledSuperficialAllocation extends Model
{
    protected $fillable = [
        'asset_code',
        'disposition_id',      // TaxPoolDisposition ID
        'window_start',        // disposition_date - 30
        'window_end',          // disposition_date + 30
        'allocated_units',     // how many units this disposition “consumed” from replacement capacity
    ];

    protected $casts = [
        'allocated_units' => AssetQuantityCast::class,
        'window_start' => 'immutable_date',
        'window_end' => 'immutable_date',
    ];
}
