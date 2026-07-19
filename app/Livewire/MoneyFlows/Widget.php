<?php

namespace App\Livewire\MoneyFlows;

use App\Enums\SectorFlowDirection;
use App\Models\SectorFlowSnapshot;
use Livewire\Component;

/**
 * Compact, embeddable Money Flows summary: leading / accelerating / cooling
 * sectors plus the latest capture time. Clicking opens a modal with the full
 * ranking. Consumes the same persisted snapshots as the dashboard — no FMP
 * calls, no business logic duplicated here.
 *
 * Embed anywhere with <livewire:money-flows.widget />.
 */
class Widget extends Component
{
    public string $interval = SectorFlowSnapshot::INTERVAL_EOD;

    public int $topN = 3;

    public function render()
    {
        $snapshots = SectorFlowSnapshot::query()
            ->latestForInterval($this->interval)
            ->get();

        $ranked = $snapshots
            ->sortByDesc(fn (SectorFlowSnapshot $s) => (float) ($s->strength ?? -1))
            ->values();

        $accelerating = $ranked
            ->where('direction', SectorFlowDirection::Accelerating->value)
            ->take($this->topN)
            ->values();

        $cooling = $ranked
            ->whereIn('direction', [
                SectorFlowDirection::Cooling->value,
                SectorFlowDirection::Weakening->value,
            ])
            ->sortBy(fn (SectorFlowSnapshot $s) => (float) ($s->strength ?? 999))
            ->take($this->topN)
            ->values();

        return view('livewire.money-flows.widget', [
            'ranked' => $ranked,
            'leading' => $ranked->take($this->topN),
            'accelerating' => $accelerating,
            'cooling' => $cooling,
            'latestCapturedAt' => $snapshots->max('captured_at'),
        ]);
    }
}
