<?php

namespace App\Models;

use App\Models\Concerns\HasComputedMarketCap;
use Illuminate\Database\Eloquent\Model;

/**
 * Alert row produced by the EPS Revision scanner — i.e. when an analyst
 * consensus EPS for the next quarter is raised or cut beyond the
 * configured threshold versus the previously stored value.
 *
 * @property int $id
 * @property string $symbol
 * @property string|null $company_name
 * @property string|null $exchange
 * @property \Illuminate\Support\Carbon|null $next_quarter_end_date
 * @property string $previous_estimate
 * @property string $latest_estimate
 * @property string $revision_percent
 * @property string $direction
 * @property string $alert_type
 * @property string $status
 * @property int|null $market_cap Computed: price × shares_outstanding (falls back to stored column).
 * @property string|null $price
 * @property int|null $shares_outstanding
 * @property int|null $float_shares
 * @property string|null $free_float
 * @property string|null $message
 * @property string $source
 * @property \Illuminate\Support\Carbon|null $detected_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class EpsRevisionAlert extends Model
{
    use HasComputedMarketCap;

    public const DIRECTION_POSITIVE = 'positive';

    public const DIRECTION_NEGATIVE = 'negative';

    protected $fillable = [
        'symbol',
        'company_name',
        'exchange',
        'next_quarter_end_date',
        'previous_estimate',
        'latest_estimate',
        'revision_percent',
        'direction',
        'alert_type',
        'status',
        'market_cap',
        'price',
        'shares_outstanding',
        'float_shares',
        'free_float',
        'message',
        'source',
        'detected_at',
        'sent_at',
    ];

    protected $casts = [
        'next_quarter_end_date' => 'date',
        'previous_estimate' => 'decimal:6',
        'latest_estimate' => 'decimal:6',
        'revision_percent' => 'decimal:4',
        'market_cap' => 'integer',
        'price' => 'decimal:4',
        'shares_outstanding' => 'integer',
        'float_shares' => 'integer',
        'free_float' => 'decimal:4',
        'detected_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
