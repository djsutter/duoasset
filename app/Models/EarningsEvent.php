<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $symbol
 * @property string|null $exchange
 * @property string|null $company_name
 * @property \Illuminate\Support\Carbon $report_date
 * @property string|null $report_time
 * @property string|null $fiscal_period
 * @property string|null $eps_estimated
 * @property string|null $eps_actual
 * @property string|null $eps_surprise
 * @property string|null $eps_surprise_percent
 * @property int|null $revenue_estimated
 * @property int|null $revenue_actual
 * @property string|null $revenue_surprise_percent
 * @property int|null $market_cap
 * @property string|null $price
 * @property int|null $volume
 * @property int|null $avg_volume
 * @property string|null $relative_volume
 * @property string $source
 * @property array|null $raw
 * @property \Illuminate\Support\Carbon|null $detected_at
 */
class EarningsEvent extends Model
{
    protected $fillable = [
        'symbol',
        'exchange',
        'company_name',
        'report_date',
        'report_time',
        'fiscal_period',
        'eps_estimated',
        'eps_actual',
        'eps_surprise',
        'eps_surprise_percent',
        'revenue_estimated',
        'revenue_actual',
        'revenue_surprise_percent',
        'market_cap',
        'price',
        'volume',
        'avg_volume',
        'relative_volume',
        'source',
        'raw',
        'detected_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'detected_at' => 'datetime',
        'raw' => 'array',
        'eps_estimated' => 'decimal:4',
        'eps_actual' => 'decimal:4',
        'eps_surprise' => 'decimal:4',
        'eps_surprise_percent' => 'decimal:4',
        'revenue_surprise_percent' => 'decimal:4',
        'price' => 'decimal:4',
        'relative_volume' => 'decimal:4',
        'revenue_estimated' => 'integer',
        'revenue_actual' => 'integer',
        'market_cap' => 'integer',
        'volume' => 'integer',
        'avg_volume' => 'integer',
    ];

    public function alert(): HasOne
    {
        return $this->hasOne(EarningsAlert::class);
    }
}
