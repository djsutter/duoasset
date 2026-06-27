<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Latest analyst consensus EPS estimate snapshot per (symbol, period).
 * The EPS Revision scanner upserts here whenever it detects a change vs
 * the previously stored estimate.
 *
 * @property int $id
 * @property string $symbol
 * @property \Illuminate\Support\Carbon|null $next_quarter_end_date
 * @property string $eps_estimate
 * @property \Illuminate\Support\Carbon|null $collected_at
 * @property string $source
 */
class EpsEstimateHistory extends Model
{
    protected $table = 'eps_estimate_history';

    protected $fillable = [
        'symbol',
        'next_quarter_end_date',
        'eps_estimate',
        'collected_at',
        'source',
    ];

    protected $casts = [
        'next_quarter_end_date' => 'date',
        'collected_at' => 'datetime',
        'eps_estimate' => 'decimal:6',
    ];
}
