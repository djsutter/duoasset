<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Sector Money Flows capture per (sector, snapshot_date, captured_slot).
 *
 * This is the persisted output of the moneyflow:update engine and the sole
 * data source for the Money Flows dashboard and widget — those never
 * recalculate market data at render time. See docs/sector-money-flows.md.
 *
 * @property string $sector
 * @property string|null $label
 * @property \Illuminate\Support\Carbon $snapshot_date
 * @property \Illuminate\Support\Carbon|null $captured_at
 * @property string $interval
 * @property string $captured_slot
 * @property string|null $direction
 * @property int $etf_count
 * @property array<string, mixed>|null $constituents
 */
class SectorFlowSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\SectorFlowSnapshotFactory> */
    use HasFactory;

    public const INTERVAL_EOD = 'eod';

    public const INTERVAL_HOURLY = 'hourly';

    public const SLOT_EOD = 'eod';

    /** Timeframes measured on every capture, shortest to longest. */
    public const TIMEFRAMES = ['hourly', 'daily', 'weekly', 'monthly'];

    protected $fillable = [
        'sector',
        'label',
        'snapshot_date',
        'captured_at',
        'interval',
        'captured_slot',

        'hourly_change_pct',
        'daily_change_pct',
        'weekly_change_pct',
        'monthly_change_pct',

        'hourly_relative_strength',
        'daily_relative_strength',
        'weekly_relative_strength',
        'monthly_relative_strength',

        'hourly_relative_volume',
        'daily_relative_volume',
        'weekly_relative_volume',
        'monthly_relative_volume',

        'hourly_relative_dollar_volume',
        'daily_relative_dollar_volume',
        'weekly_relative_dollar_volume',
        'monthly_relative_dollar_volume',

        'hourly_score',
        'daily_score',
        'weekly_score',
        'monthly_score',
        'strength',

        'rank',
        'percentile_rank',

        'hourly_velocity',
        'daily_velocity',
        'weekly_velocity',
        'monthly_velocity',
        'velocity',

        'hourly_acceleration',
        'daily_acceleration',
        'weekly_acceleration',
        'monthly_acceleration',
        'acceleration',

        'issuer_breadth_hourly',
        'issuer_breadth_daily',
        'issuer_breadth_weekly',
        'issuer_breadth_monthly',

        'direction',
        'confidence_score',
        'data_quality_score',

        'etf_count',
        'constituents',
    ];

    protected $casts = [
        'captured_at' => 'datetime',

        'hourly_change_pct' => 'decimal:4',
        'daily_change_pct' => 'decimal:4',
        'weekly_change_pct' => 'decimal:4',
        'monthly_change_pct' => 'decimal:4',

        'hourly_relative_strength' => 'decimal:4',
        'daily_relative_strength' => 'decimal:4',
        'weekly_relative_strength' => 'decimal:4',
        'monthly_relative_strength' => 'decimal:4',

        'hourly_relative_volume' => 'decimal:4',
        'daily_relative_volume' => 'decimal:4',
        'weekly_relative_volume' => 'decimal:4',
        'monthly_relative_volume' => 'decimal:4',

        'hourly_relative_dollar_volume' => 'decimal:4',
        'daily_relative_dollar_volume' => 'decimal:4',
        'weekly_relative_dollar_volume' => 'decimal:4',
        'monthly_relative_dollar_volume' => 'decimal:4',

        'hourly_score' => 'decimal:2',
        'daily_score' => 'decimal:2',
        'weekly_score' => 'decimal:2',
        'monthly_score' => 'decimal:2',
        'strength' => 'decimal:2',

        'rank' => 'integer',
        'percentile_rank' => 'decimal:2',

        'hourly_velocity' => 'decimal:6',
        'daily_velocity' => 'decimal:6',
        'weekly_velocity' => 'decimal:6',
        'monthly_velocity' => 'decimal:6',
        'velocity' => 'decimal:6',

        'hourly_acceleration' => 'decimal:6',
        'daily_acceleration' => 'decimal:6',
        'weekly_acceleration' => 'decimal:6',
        'monthly_acceleration' => 'decimal:6',
        'acceleration' => 'decimal:6',

        'issuer_breadth_hourly' => 'decimal:2',
        'issuer_breadth_daily' => 'decimal:2',
        'issuer_breadth_weekly' => 'decimal:2',
        'issuer_breadth_monthly' => 'decimal:2',

        'confidence_score' => 'decimal:2',
        'data_quality_score' => 'decimal:2',

        'etf_count' => 'integer',
        'constituents' => 'array',
    ];

    /**
     * Trading date the snapshot describes.
     *
     * Stored as a canonical Y-m-d string (not a datetime) so that
     * updateOrCreate(['sector' => .., 'snapshot_date' => 'Y-m-d', ..]) is
     * idempotent on every driver — SQLite keeps the literal string, so a
     * 'date' cast (which serializes to 'Y-m-d 00:00:00') would never match
     * a 'Y-m-d' lookup. Reads still return a Carbon for convenience.
     *
     * @return Attribute<Carbon|null, string|null>
     */
    protected function snapshotDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? Carbon::parse($value) : null,
            set: fn ($value) => $value !== null ? Carbon::parse($value)->toDateString() : null,
        );
    }

    /**
     * The most recent capture for each sector — the dashboard/widget view.
     *
     * Ordered by captured_at so intraday hourly captures are superseded by
     * later ones (and by the end-of-day capture once it runs). Exactly one
     * row per sector is returned; callers add their own ordering.
     *
     * @param  Builder<SectorFlowSnapshot>  $query
     * @return Builder<SectorFlowSnapshot>
     */
    public function scopeLatestPerSector(Builder $query): Builder
    {
        $table = $this->getTable();

        $latest = static::query()
            ->selectRaw('sector, MAX(captured_at) as max_captured_at')
            ->groupBy('sector');

        return $query->joinSub(
            $latest,
            'latest_flow',
            function ($join) use ($table) {
                $join->on("$table.sector", '=', 'latest_flow.sector')
                    ->on("$table.captured_at", '=', 'latest_flow.max_captured_at');
            },
        )->select("$table.*");
    }

    /**
     * Restrict to a capture cadence ('eod' or 'hourly').
     *
     * @param  Builder<SectorFlowSnapshot>  $query
     * @return Builder<SectorFlowSnapshot>
     */
    public function scopeForInterval(Builder $query, string $interval): Builder
    {
        return $query->where('interval', $interval);
    }

    /**
     * The most recent capture per sector for a single cadence — what the
     * dashboard and widget render for a chosen interval (eod or hourly).
     *
     * @param  Builder<SectorFlowSnapshot>  $query
     * @return Builder<SectorFlowSnapshot>
     */
    public function scopeLatestForInterval(Builder $query, string $interval): Builder
    {
        $table = $this->getTable();

        $latest = static::query()
            ->where('interval', $interval)
            ->selectRaw('sector, MAX(captured_at) as max_captured_at')
            ->groupBy('sector');

        return $query
            ->where("$table.interval", $interval)
            ->joinSub($latest, 'latest_flow', function ($join) use ($table) {
                $join->on("$table.sector", '=', 'latest_flow.sector')
                    ->on("$table.captured_at", '=', 'latest_flow.max_captured_at');
            })
            ->select("$table.*");
    }

    /**
     * Restrict to a single trading date.
     *
     * @param  Builder<SectorFlowSnapshot>  $query
     * @return Builder<SectorFlowSnapshot>
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('snapshot_date', $date);
    }
}
