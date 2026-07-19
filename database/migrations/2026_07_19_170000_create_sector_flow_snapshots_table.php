<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sector Money Flows snapshots.
 *
 * A snapshot is one capture of one sector's flow metrics. Two cadences share
 * this table, distinguished by `interval` + `captured_slot`:
 *   - 'eod'    : the authoritative end-of-day capture (captured_slot = 'eod').
 *   - 'hourly' : an intraday capture (captured_slot = market-hour, e.g. '10'),
 *                so intraday traders can watch flows move through the session.
 *
 * Identity is UNIQUE(sector, snapshot_date, captured_slot): one row per sector
 * per trading date per slot. `snapshot_date` is the trading date; `captured_at`
 * is when the calculation actually ran.
 *
 * Design notes:
 *  - No synthetic sector "price" columns — the five ETFs have different share
 *    prices and cannot be averaged into one price. Metrics are percentage-,
 *    score-, participation- and ratio-based; per-ETF raw prices/volumes live
 *    in the `constituents` JSON.
 *  - Volumes are stored only as normalized participation ratios, never as
 *    summed raw share volume (not comparable across ETFs).
 *  - Every timeframe (hourly/daily/weekly/monthly) is measured as-of the
 *    capture time. velocity/acceleration are legitimately null until enough
 *    prior same-interval snapshots exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sector_flow_snapshots', function (Blueprint $table) {
            $table->id();

            // Canonical money-flow sector key (config('market_data.sector_etfs')).
            $table->string('sector', 64);
            $table->string('label')->nullable();

            // Trading date the metrics describe vs. when the calc executed.
            $table->date('snapshot_date');
            $table->timestamp('captured_at')->nullable();

            // Capture cadence. 'eod' = authoritative daily; 'hourly' = intraday.
            $table->string('interval', 8)->default('eod');
            // Slot within the trading date. 'eod' for the daily capture; the
            // market-timezone hour (e.g. '10'..'16') for hourly captures.
            $table->string('captured_slot', 8)->default('eod');

            // Per-period percentage change (weighted average of ETF returns).
            $table->decimal('hourly_change_pct', 10, 4)->nullable();
            $table->decimal('daily_change_pct', 10, 4)->nullable();
            $table->decimal('weekly_change_pct', 10, 4)->nullable();
            $table->decimal('monthly_change_pct', 10, 4)->nullable();

            // Per-period relative strength vs. the benchmark (SPY).
            $table->decimal('hourly_relative_strength', 10, 4)->nullable();
            $table->decimal('daily_relative_strength', 10, 4)->nullable();
            $table->decimal('weekly_relative_strength', 10, 4)->nullable();
            $table->decimal('monthly_relative_strength', 10, 4)->nullable();

            // Per-period participation: volume vs. each ETF's own average.
            $table->decimal('hourly_relative_volume', 10, 4)->nullable();
            $table->decimal('daily_relative_volume', 10, 4)->nullable();
            $table->decimal('weekly_relative_volume', 10, 4)->nullable();
            $table->decimal('monthly_relative_volume', 10, 4)->nullable();

            // Per-period participation in dollar-volume terms.
            $table->decimal('hourly_relative_dollar_volume', 10, 4)->nullable();
            $table->decimal('daily_relative_dollar_volume', 10, 4)->nullable();
            $table->decimal('weekly_relative_dollar_volume', 10, 4)->nullable();
            $table->decimal('monthly_relative_dollar_volume', 10, 4)->nullable();

            // Absolute, ETF-derived scores (normalized against ETF history),
            // 0-100. `strength` is the composite absolute score.
            $table->decimal('hourly_score', 6, 2)->nullable();
            $table->decimal('daily_score', 6, 2)->nullable();
            $table->decimal('weekly_score', 6, 2)->nullable();
            $table->decimal('monthly_score', 6, 2)->nullable();
            $table->decimal('strength', 6, 2)->nullable();

            // Cross-sectional standing across the sectors in the same run.
            // Kept alongside the absolute scores so a sector that is merely
            // "less weak" than its peers is not mistaken for strong.
            $table->unsignedSmallInteger('rank')->nullable();
            $table->decimal('percentile_rank', 6, 2)->nullable();

            // Rate of score change (per-period, plus a composite).
            $table->decimal('hourly_velocity', 12, 6)->nullable();
            $table->decimal('daily_velocity', 12, 6)->nullable();
            $table->decimal('weekly_velocity', 12, 6)->nullable();
            $table->decimal('monthly_velocity', 12, 6)->nullable();
            $table->decimal('velocity', 12, 6)->nullable();

            // Rate of change of velocity (per-period, plus a composite).
            $table->decimal('hourly_acceleration', 12, 6)->nullable();
            $table->decimal('daily_acceleration', 12, 6)->nullable();
            $table->decimal('weekly_acceleration', 12, 6)->nullable();
            $table->decimal('monthly_acceleration', 12, 6)->nullable();
            $table->decimal('acceleration', 12, 6)->nullable();

            // Issuer agreement: % of valid ETFs outperforming the benchmark.
            $table->decimal('issuer_breadth_hourly', 6, 2)->nullable();
            $table->decimal('issuer_breadth_daily', 6, 2)->nullable();
            $table->decimal('issuer_breadth_weekly', 6, 2)->nullable();
            $table->decimal('issuer_breadth_monthly', 6, 2)->nullable();

            // Classified trend + data-integrity signals.
            $table->string('direction', 20)->nullable();
            $table->decimal('confidence_score', 6, 2)->nullable();
            $table->decimal('data_quality_score', 6, 2)->nullable();

            // Number of valid ETFs that contributed + per-ETF breakdown.
            $table->unsignedTinyInteger('etf_count')->default(0);
            $table->json('constituents')->nullable();

            $table->timestamps();

            // One authoritative snapshot per sector per trading date per slot.
            $table->unique(['sector', 'snapshot_date', 'captured_slot'], 'sector_flow_snapshots_identity_uniq');

            // Dashboard reads the newest capture and sorts by standing.
            $table->index('snapshot_date');
            $table->index(['snapshot_date', 'strength']);
            $table->index('direction');
            // "previous same-interval snapshot" lookups (velocity/acceleration)
            // and latest-per-sector ordering.
            $table->index(['sector', 'interval', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_flow_snapshots');
    }
};
