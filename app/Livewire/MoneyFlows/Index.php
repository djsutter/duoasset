<?php

namespace App\Livewire\MoneyFlows;

use App\Models\SectorFlowSnapshot;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Money Flows dashboard: one row per sector, showing the latest persisted
 * snapshot for the chosen cadence. Reads only stored snapshots — it never
 * recalculates market data or calls FMP at render time.
 */
class Index extends Component
{
    /** Columns the table may be sorted by (allow-list — never trust input). */
    private const SORTABLE = [
        'sector', 'strength', 'rank', 'hourly_score', 'daily_score', 'weekly_score',
        'monthly_score', 'velocity', 'acceleration', 'issuer_breadth_weekly',
        'hourly_change_pct', 'daily_change_pct', 'weekly_change_pct', 'monthly_change_pct',
    ];

    #[Url(as: 'interval')]
    public string $interval = SectorFlowSnapshot::INTERVAL_EOD;

    #[Url(as: 'sort')]
    public string $sortBy = 'strength';

    #[Url(as: 'dir')]
    public string $sortDirection = 'desc';

    public function setInterval(string $interval): void
    {
        if (in_array($interval, [SectorFlowSnapshot::INTERVAL_EOD, SectorFlowSnapshot::INTERVAL_HOURLY], true)) {
            $this->interval = $interval;
        }
    }

    public function sortByColumn(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = $column === 'sector' ? 'asc' : 'desc';
        }
    }

    public function render()
    {
        $snapshots = SectorFlowSnapshot::query()
            ->latestForInterval($this->interval)
            ->get();

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'strength';
        $direction = strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';

        return view('livewire.money-flows.index', [
            'snapshots' => $this->sortSnapshots($snapshots, $sortBy, $direction),
            'latestCapturedAt' => $snapshots->max('captured_at'),
            'sortBy' => $sortBy,
            'sortDirection' => $direction,
        ]);
    }

    /**
     * PHP-side sort (only ~11 rows) with nulls always last, numeric-aware.
     *
     * @param  Collection<int, SectorFlowSnapshot>  $snapshots
     * @return Collection<int, SectorFlowSnapshot>
     */
    private function sortSnapshots(Collection $snapshots, string $key, string $direction): Collection
    {
        return $snapshots->sort(function (SectorFlowSnapshot $a, SectorFlowSnapshot $b) use ($key, $direction) {
            $av = $a->{$key};
            $bv = $b->{$key};

            if ($av === null && $bv === null) {
                return 0;
            }
            if ($av === null) {
                return 1; // nulls last regardless of direction
            }
            if ($bv === null) {
                return -1;
            }

            $cmp = is_numeric($av) && is_numeric($bv)
                ? ((float) $av <=> (float) $bv)
                : strcasecmp((string) $av, (string) $bv);

            return $direction === 'asc' ? $cmp : -$cmp;
        })->values();
    }
}
