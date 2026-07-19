<?php

namespace App\Console\Commands;

use App\Models\SectorFlowSnapshot;
use App\Services\MoneyFlow\SectorMoneyFlowRunSummary;
use App\Services\MoneyFlow\SectorMoneyFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Drive the Sector Money Flows engine: one coordinated whole-market pass that
 * fetches ETF + benchmark bars, computes every sector's flow metrics, ranks
 * them cross-sectionally, and persists one snapshot per sector per slot.
 *
 * Runs synchronously (no per-symbol job fan-out) because sector ranking and
 * benchmarking are cross-sectional — hence there is no --sync option.
 */
class UpdateMoneyFlows extends Command
{
    protected $signature = 'moneyflow:update
        {--sector=* : restrict to one or more sector keys (default: all configured)}
        {--interval=eod : capture cadence: eod (authoritative daily) or hourly (intraday)}
        {--force : run even when the engine is disabled in config}
        {--verbose-table : print a per-sector result table}';

    protected $description = 'Capture Sector Money Flows snapshots from the major North American sector ETFs.';

    public function handle(SectorMoneyFlowService $service): int
    {
        $enabled = (bool) config('market_data.moneyflow.enabled', true);
        if (! $enabled && ! $this->option('force')) {
            $this->warn('Sector Money Flows engine is disabled (MONEYFLOW_ENABLED=false). Use --force to override.');

            return self::SUCCESS;
        }

        $interval = strtolower(trim((string) $this->option('interval'))) ?: SectorFlowSnapshot::INTERVAL_EOD;
        if (! in_array($interval, [SectorFlowSnapshot::INTERVAL_EOD, SectorFlowSnapshot::INTERVAL_HOURLY], true)) {
            $this->error("Invalid --interval [{$interval}]. Use 'eod' or 'hourly'.");

            return self::INVALID;
        }

        $sectors = $this->resolveSectors();
        if ($sectors === false) {
            return self::INVALID;
        }

        $timezone = (string) config('market_data.moneyflow.market_timezone', 'America/New_York');
        $asOf = CarbonImmutable::now($timezone);

        $this->info(sprintf(
            'Money Flows: %s capture for %s sector(s) as of %s %s.',
            $interval,
            $sectors === null ? 'all' : (string) count($sectors),
            $asOf->toDateTimeString(),
            $timezone,
        ));

        try {
            $summary = $service->capture($interval, $sectors, $asOf);
        } catch (\Throwable $e) {
            Log::error('moneyflow.update_failed', ['message' => $e->getMessage()]);
            $this->error('Money Flows run failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->reportProviderErrors($summary);
        $this->reportSummary($summary);

        if ($this->option('verbose-table') && $summary->hasPublished()) {
            $this->renderTable($summary);
        }

        // Failure only when the run produced no usable result at all.
        if (! $summary->hasPublished()) {
            $this->error('No sectors could be published (see skipped reasons / provider errors above).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null|false null = all sectors, false = invalid input
     */
    private function resolveSectors(): array|null|false
    {
        $configured = array_keys((array) config('market_data.sector_etfs', []));
        if ($configured === []) {
            $this->error('No sector ETFs are configured (market_data.sector_etfs is empty).');

            return false;
        }

        $requested = array_values(array_filter(array_map(
            fn ($s) => strtolower(trim((string) $s)),
            (array) $this->option('sector'),
        )));

        if ($requested === []) {
            return null;
        }

        $unknown = array_diff($requested, $configured);
        if ($unknown !== []) {
            $this->error('Unknown sector key(s): '.implode(', ', $unknown));
            $this->line('Configured sectors: '.implode(', ', $configured));

            return false;
        }

        return $requested;
    }

    private function reportSummary(SectorMoneyFlowRunSummary $summary): void
    {
        $this->info(sprintf(
            'Published %d sector(s) for %s (slot: %s).',
            $summary->publishedCount(),
            $summary->snapshotDate,
            $summary->capturedSlot,
        ));

        foreach ($summary->skipped as $sector => $reason) {
            $this->warn("Skipped {$sector}: {$reason}");
        }
    }

    private function reportProviderErrors(SectorMoneyFlowRunSummary $summary): void
    {
        foreach ($summary->providerErrors as $err) {
            $hint = match ((int) ($err['status'] ?? 0)) {
                401, 403 => ' (check FMP_API_KEY)',
                402 => ' (your FMP plan does not allow this endpoint)',
                429 => ' (rate limit hit)',
                default => '',
            };
            $this->warn(sprintf(
                'FMP %s returned HTTP %d%s: %s',
                $err['path'] ?? '?',
                (int) ($err['status'] ?? 0),
                $hint,
                trim((string) ($err['body'] ?? '')),
            ));
        }
    }

    private function renderTable(SectorMoneyFlowRunSummary $summary): void
    {
        $rows = SectorFlowSnapshot::query()
            ->whereIn('sector', $summary->publishedSectors)
            ->where('snapshot_date', $summary->snapshotDate)
            ->where('captured_slot', $summary->capturedSlot)
            ->orderBy('rank')
            ->get([
                'sector', 'strength', 'rank', 'daily_change_pct', 'weekly_change_pct',
                'monthly_change_pct', 'velocity', 'acceleration', 'direction', 'etf_count',
            ]);

        $this->table(
            ['Sector', 'Strength', 'Rank', '1D%', '1W%', '1M%', 'Vel', 'Accel', 'Direction', 'ETFs'],
            $rows->map(fn (SectorFlowSnapshot $r) => [
                $r->sector,
                $r->strength,
                $r->rank,
                $r->daily_change_pct,
                $r->weekly_change_pct,
                $r->monthly_change_pct,
                $r->velocity,
                $r->acceleration,
                $r->direction,
                $r->etf_count,
            ])->all(),
        );
    }
}
