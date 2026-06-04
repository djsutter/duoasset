<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxPool extends Model
{
    protected $fillable = [
        'asset_code',
        'total_quantity',
        'total_acb',
    ];

    protected $casts = [
        'total_quantity' => AssetQuantityCast::class,
        'total_acb' => MoneyCast::class,
    ];

    public function dispositions(): HasMany
    {
        return $this->hasMany(TaxPoolDisposition::class, 'asset_code', 'asset_code');
    }
}
