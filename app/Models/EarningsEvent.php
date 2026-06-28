<?php

namespace App\Models;

use App\Models\Concerns\HasComputedMarketCap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property int|null $market_cap Computed: price × shares_outstanding (falls back to stored column).
 * @property int|null $shares_outstanding
 * @property int|null $float_shares
 * @property string|null $free_float
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
    use HasComputedMarketCap;

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
        'shares_outstanding',
        'float_shares',
        'free_float',
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
        'shares_outstanding' => 'integer',
        'float_shares' => 'integer',
        'free_float' => 'decimal:4',
        'volume' => 'integer',
        'avg_volume' => 'integer',
    ];

    /**
     * Legacy single-alert accessor (returns the first/positive direction alert).
     * Retained so older callers/tests that expect a 1:1 relation keep working.
     */
    public function alert(): HasOne
    {
        return $this->hasOne(EarningsAlert::class);
    }

    /**
     * All alerts for this event — there can now be one per (alert_type, direction).
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(EarningsAlert::class);
    }
}
