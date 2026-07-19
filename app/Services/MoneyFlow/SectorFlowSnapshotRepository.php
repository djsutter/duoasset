<?php

namespace App\Services\MoneyFlow;

use App\Models\SectorFlowSnapshot;
use Carbon\CarbonInterface;

/**
 * Persistence + prior-snapshot lookups for the money-flow engine. Keeps
 * Eloquent access out of the calculation services.
 */
class SectorFlowSnapshotRepository
{
    /**
     * The most recent snapshot for a sector on the same cadence strictly
     * before the given capture time — the reference point for velocity and
     * acceleration. Same-interval so hourly compares to hourly, eod to eod.
     */
    public function previous(string $sector, string $interval, CarbonInterface $before): ?SectorFlowSnapshot
    {
        return SectorFlowSnapshot::query()
            ->where('sector', $sector)
            ->where('interval', $interval)
            ->where('captured_at', '<', $before)
            ->orderByDesc('captured_at')
            ->first();
    }

    /**
     * Idempotently persist one capture. Identity is
     * (sector, snapshot_date, captured_slot) so re-running a slot updates it.
     */
    public function persist(SectorFlowSnapshotData $data): SectorFlowSnapshot
    {
        return SectorFlowSnapshot::updateOrCreate($data->identity(), $data->values());
    }
}
