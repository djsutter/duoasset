<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One authoritative Sector Money Flows snapshot per (sector, snapshot_date).
 *
 * Phase 1 is end-of-day: the moneyflow:update command produces exactly one
 * row per sector per trading date. `snapshot_date` is the trading date the
 * metrics describe; `captured_at` is when the calculation actually ran.
 *
 * Design notes:
 *  - No synthetic sector "price" columns. The five ETFs in a sector have
 *    different share prices and cannot be averaged into one price. Metrics
 *    here are percentage-, score-, participation- and ratio-based. Per-ETF
 *    raw prices/volumes live inside the `constituents` JSON for transparency.
 *  - Volumes are stored only as normalized participation ratios
 *    (relative volume / relative dollar volume), never as summed raw share
 *    volume, which is not comparable across ETFs.
 *  - Every column below is a value the engine computes (Phase 2 populates
 *    them). velocity/acceleration are legitimately null on the first/second
 *    snapshot per sector.
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

            // Per-period percentage change (weighted average of ETF returns).
            $table->decimal('daily_change_pct', 10, 4)->nullable();
            $table->decimal('weekly_change_pct', 10, 4)->nullable();
            $table->decimal('monthly_change_pct', 10, 4)->nullable();

            // Per-period relative strength vs. the benchmark (SPY).
            $table->decimal('daily_relative_strength', 10, 4)->nullable();
            $table->decimal('weekly_relative_strength', 10, 4)->nullable();
            $table->decimal('monthly_relative_strength', 10, 4)->nullable();

            // Per-period participation: volume vs. each ETF's own average.
            $table->decimal('daily_relative_volume', 10, 4)->nullable();
            $table->decimal('weekly_relative_volume', 10, 4)->nullable();
            $table->decimal('monthly_relative_volume', 10, 4)->nullable();

            // Per-period participation in dollar-volume terms.
            $table->decimal('daily_relative_dollar_volume', 10, 4)->nullable();
            $table->decimal('weekly_relative_dollar_volume', 10, 4)->nullable();
            $table->decimal('monthly_relative_dollar_volume', 10, 4)->nullable();

            // Absolute, ETF-derived scores (normalized against ETF history),
            // 0-100. `strength` is the composite absolute score.
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
            $table->decimal('daily_velocity', 12, 6)->nullable();
            $table->decimal('weekly_velocity', 12, 6)->nullable();
            $table->decimal('monthly_velocity', 12, 6)->nullable();
            $table->decimal('velocity', 12, 6)->nullable();

            // Rate of change of velocity (per-period, plus a composite).
            $table->decimal('daily_acceleration', 12, 6)->nullable();
            $table->decimal('weekly_acceleration', 12, 6)->nullable();
            $table->decimal('monthly_acceleration', 12, 6)->nullable();
            $table->decimal('acceleration', 12, 6)->nullable();

            // Issuer agreement: % of valid ETFs outperforming the benchmark.
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

            // One authoritative snapshot per sector per trading date.
            $table->unique(['sector', 'snapshot_date'], 'sector_flow_snapshots_sector_date_uniq');

            // Dashboard reads the newest date and sorts by standing.
            $table->index('snapshot_date');
            $table->index(['snapshot_date', 'strength']);
            $table->index('direction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_flow_snapshots');
    }
};
