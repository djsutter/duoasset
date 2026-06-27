<?php

namespace App\Models;

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
 * @property int|null $market_cap
 * @property string|null $message
 * @property string $source
 * @property \Illuminate\Support\Carbon|null $detected_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class EpsRevisionAlert extends Model
{
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
        'detected_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
