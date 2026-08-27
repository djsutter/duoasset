<?php

namespace App\Models;

use App\Models\Concerns\HasComputedMarketCap;
use Illuminate\Database\Eloquent\Model;

/**
 * Alert row produced by the Stock Buy Setup scanner — a high-volume spike
 * following a tight consolidation base, scored 0–100 by heartbeat_score.
 *
 * @property int $id
 * @property string $source
 * @property string $symbol
 * @property string $setup_type
 * @property int $setup_score
 * @property int $raw_setup_score
 * @property string|null $company_name
 * @property string|null $exchange
 * @property int|null $market_cap Computed: price × shares_outstanding (falls back to stored column).
 * @property string|null $market_cap_category
 * @property string|null $price
 * @property int|null $shares_outstanding
 * @property int|null $float_shares
 * @property string|null $free_float
 * @property int|null $avg_daily_volume
 * @property string|null $liquidity_turnover_pct
 * @property string|null $liquidity_penalty_pct
 * @property int $liquidity_penalty_points
 * @property \Illuminate\Support\Carbon $spike_date
 * @property int|null $spike_volume
 * @property int|null $prior_52w_max_volume
 * @property int|null $max_104w_volume
 * @property bool $is_52w_high_volume
 * @property bool $is_104w_high_volume
 * @property int|null $days_since_previous_comparable_spike
 * @property int|null $spike_age_bars
 * @property int $spike_rarity_points
 * @property string|null $spike_rarity_description
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
 * @property string|null $quarterly_eps_growth_pct
 * @property string|null $quarterly_revenue_growth_pct
 * @property string|null $annual_eps_growth_pct
 * @property string|null $roe_pct
 * @property string|null $profit_margin_pct
 * @property string|null $spike_relative_volume
 * @property array|null $eps_growth_sequence
 * @property array|null $revenue_growth_sequence
 * @property string|null $operating_margin_expansion_bps
 * @property string|null $current_ttm_operating_margin
 * @property string|null $prior_ttm_operating_margin
 * @property int $heartbeat_score
 * @property string|null $reason_summary
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $detected_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class StockBuySetupAlert extends Model
{
    use HasComputedMarketCap;

    protected $fillable = [
        'source',
        'symbol',
        'setup_type',
        'setup_score',
        'raw_setup_score',
        'company_name',
        'exchange',
        'market_cap',
        'market_cap_category',
        'price',
        'shares_outstanding',
        'float_shares',
        'free_float',
        'avg_daily_volume',
        'liquidity_turnover_pct',
        'liquidity_penalty_pct',
        'liquidity_penalty_points',
        'spike_date',
        'spike_volume',
        'prior_52w_max_volume',
        'max_104w_volume',
        'is_52w_high_volume',
        'is_104w_high_volume',
        'days_since_previous_comparable_spike',
        'spike_age_bars',
        'spike_rarity_points',
        'spike_rarity_description',
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
        'quarterly_eps_growth_pct',
        'quarterly_revenue_growth_pct',
        'annual_eps_growth_pct',
        'roe_pct',
        'profit_margin_pct',
        'spike_relative_volume',
        'eps_growth_sequence',
        'revenue_growth_sequence',
        'operating_margin_expansion_bps',
        'current_ttm_operating_margin',
        'prior_ttm_operating_margin',
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
        'spike_age_bars' => 'integer',
        'spike_rarity_points' => 'integer',
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
        'quarterly_eps_growth_pct' => 'decimal:4',
        'quarterly_revenue_growth_pct' => 'decimal:4',
        'annual_eps_growth_pct' => 'decimal:4',
        'roe_pct' => 'decimal:4',
        'profit_margin_pct' => 'decimal:4',
        'spike_relative_volume' => 'decimal:4',
        'eps_growth_sequence' => 'array',
        'revenue_growth_sequence' => 'array',
        'operating_margin_expansion_bps' => 'decimal:4',
        'current_ttm_operating_margin' => 'decimal:6',
        'prior_ttm_operating_margin' => 'decimal:6',
        'setup_score' => 'integer',
        'raw_setup_score' => 'integer',
        'market_cap' => 'integer',
        'price' => 'decimal:4',
        'shares_outstanding' => 'integer',
        'float_shares' => 'integer',
        'free_float' => 'decimal:4',
        'avg_daily_volume' => 'integer',
        'liquidity_turnover_pct' => 'decimal:6',
        'liquidity_penalty_pct' => 'decimal:4',
        'liquidity_penalty_points' => 'integer',
        'heartbeat_score' => 'integer',
        'detected_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
