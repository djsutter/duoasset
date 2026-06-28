<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Alert row produced by the Stock Buy Setup scanner — a high-volume spike
 * following a tight consolidation base, scored 0–100 by heartbeat_score.
 *
 * @property int $id
 * @property string $source
 * @property string $symbol
 * @property string|null $company_name
 * @property string|null $exchange
 * @property int|null $market_cap
 * @property string|null $market_cap_category
 * @property \Illuminate\Support\Carbon $spike_date
 * @property int|null $spike_volume
 * @property int|null $prior_52w_max_volume
 * @property int|null $max_104w_volume
 * @property bool $is_52w_high_volume
 * @property bool $is_104w_high_volume
 * @property int|null $days_since_previous_comparable_spike
 * @property \Illuminate\Support\Carbon|null $base_start_date
 * @property \Illuminate\Support\Carbon|null $base_end_date
 * @property int|null $base_duration_days
 * @property string|null $base_high
 * @property string|null $base_low
 * @property string|null $range_compression_pct
 * @property string|null $atr_contraction_ratio
 * @property string|null $volume_dry_up_score
 * @property string|null $slope
 * @property string|null $distance_to_breakout_pct
 * @property string|null $ma_alignment
 * @property string|null $relative_strength_score
 * @property string|null $earnings_acceleration
 * @property string|null $sales_acceleration
 * @property int $heartbeat_score
 * @property string|null $reason_summary
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $detected_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class StockBuySetupAlert extends Model
{
    protected $fillable = [
        'source',
        'symbol',
        'company_name',
        'exchange',
        'market_cap',
        'market_cap_category',
        'spike_date',
        'spike_volume',
        'prior_52w_max_volume',
        'max_104w_volume',
        'is_52w_high_volume',
        'is_104w_high_volume',
        'days_since_previous_comparable_spike',
        'base_start_date',
        'base_end_date',
        'base_duration_days',
        'base_high',
        'base_low',
        'range_compression_pct',
        'atr_contraction_ratio',
        'volume_dry_up_score',
        'slope',
        'distance_to_breakout_pct',
        'ma_alignment',
        'relative_strength_score',
        'earnings_acceleration',
        'sales_acceleration',
        'heartbeat_score',
        'reason_summary',
        'status',
        'detected_at',
        'sent_at',
    ];

    protected $casts = [
        'spike_date' => 'date',
        'base_start_date' => 'date',
        'base_end_date' => 'date',
        'spike_volume' => 'integer',
        'prior_52w_max_volume' => 'integer',
        'max_104w_volume' => 'integer',
        'is_52w_high_volume' => 'boolean',
        'is_104w_high_volume' => 'boolean',
        'days_since_previous_comparable_spike' => 'integer',
        'base_duration_days' => 'integer',
        'base_high' => 'decimal:6',
        'base_low' => 'decimal:6',
        'range_compression_pct' => 'decimal:4',
        'atr_contraction_ratio' => 'decimal:4',
        'volume_dry_up_score' => 'decimal:4',
        'slope' => 'decimal:8',
        'distance_to_breakout_pct' => 'decimal:4',
        'relative_strength_score' => 'decimal:4',
        'earnings_acceleration' => 'decimal:4',
        'sales_acceleration' => 'decimal:4',
        'market_cap' => 'integer',
        'heartbeat_score' => 'integer',
        'detected_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
