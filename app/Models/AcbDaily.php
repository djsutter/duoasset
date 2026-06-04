<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class AcbDaily extends Model
{
    protected $fillable = [
        'asset_code',
        'date',
        'quantity_total',
        'acb_total',
        'avg_cost_basis',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity_total' => AssetQuantityCast::class,
        'acb_total' => MoneyCast::class, // Uses reporting currency
        'avg_cost_basis' => MoneyCast::class, // Uses reporting currency
    ];
}
