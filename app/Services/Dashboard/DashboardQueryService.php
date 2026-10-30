<?php

namespace App\Services\Dashboard;

use App\Models\EarningsEvent;
use App\Models\SectorFlowSnapshot;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Models\Watchlist;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Uncached queries that feed the dashboard cards.
 */
class DashboardQueryService
{
    /**
     * Top scoring setups market-wide.
     *
     * Only the latest alert per (symbol, setup_type) is considered (an older, higher score
     * is ignored once a newer detection of the same type exists). Each symbol is then
     * represented by the highest score among its latest alerts across all setup types.
     *
     * @return Collection<int, array{symbol: string, company_name: string, setup_score: int, setup_type: string, alert: StockBuySetupAlert}>
     */
    public function topScoringSetups(int $limit = 2): Collection
    {
        $alerts = StockBuySetupAlert::query()
            ->from('stock_buy_setup_alerts as a')
            ->select('a.*')
            ->whereNotNull('a.setup_score')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('stock_buy_setup_alerts as newer')
                    ->whereColumn('newer.symbol', 'a.symbol')
                    ->whereColumn('newer.setup_type', 'a.setup_type')
                    ->where(function ($q) {
                        $q->whereColumn('newer.spike_date', '>', 'a.spike_date')
                            ->orWhere(function ($q2) {
                                $q2->whereColumn('newer.spike_date', 'a.spike_date')
                                    ->whereColumn('newer.id', '>', 'a.id');
                            });
                    });
            })
            ->get();

        if ($alerts->isEmpty()) {
            return collect();
        }

        $top = $alerts->groupBy('symbol')
            ->map(fn (Collection $symbolAlerts) => $symbolAlerts
                ->sortByDesc(fn (StockBuySetupAlert $a) => [(int) $a->setup_score, (string) $a->spike_date, $a->id])
                ->first())
            ->sortByDesc(fn (StockBuySetupAlert $a) => (int) $a->setup_score)
            ->take($limit)
            ->values();

        $stocks = Stock::query()
            ->whereIn('symbol', $top->pluck('symbol'))
            ->get()
            ->keyBy('symbol');

        return $top->map(fn (StockBuySetupAlert $alert) => [
            'symbol' => $alert->symbol,
            'company_name' => $alert->company_name ?: ($stocks->get($alert->symbol)?->company_name ?: $alert->symbol),
            'setup_score' => (int) $alert->setup_score,
            'setup_type' => (string) $alert->setup_type,
            'alert' => $alert,
        ])->values();
    }

    /**
     * Top percentage gainers since the date each item was added to the watchlist.
     *
     * @return Collection<int, array{symbol: string, company_name: string, added_at: \Illuminate\Support\Carbon, entry_price: float, latest_price: float, gain_pct: float}>
     */
    public function highestGainingStocks(Watchlist $watchlist, MarketDataProvider $provider, int $limit = 2): Collection
    {
        $results = collect();

        foreach ($watchlist->items as $item) {
            $stock = $item->stock;
            if (! $stock || empty($stock->symbol)) {
                continue;
            }

            $symbol = $stock->symbol;
            $addedAt = $item->created_at ?? now()->subDay();

            try {
                $cacheKey = "dash_stock_gain_{$item->id}_{$addedAt->timestamp}";
                $gainInfo = Cache::remember($cacheKey, 900, function () use ($provider, $symbol, $addedAt) {
                    $bars = $provider->historicalDailyBars($symbol, $addedAt->copy()->startOfDay(), now()->endOfDay());
                    if (empty($bars)) {
                        return null;
                    }

                    $firstBar = $bars[0];
                    $lastBar = end($bars);

                    $entry = (float) ($firstBar['close'] ?? $firstBar['adj_close'] ?? 0);
                    $latest = (float) ($lastBar['close'] ?? $lastBar['adj_close'] ?? 0);

                    if ($entry <= 0 || $latest <= 0) {
                        return null;
                    }

                    return [
                        'entry_price' => $entry,
                        'latest_price' => $latest,
                        'gain_pct' => round((($latest - $entry) / $entry) * 100, 2),
                    ];
                });

                if ($gainInfo) {
                    $results->push([
                        'symbol' => $symbol,
                        'company_name' => $stock->company_name ?: $symbol,
                        'added_at' => $addedAt,
                        'entry_price' => $gainInfo['entry_price'],
                        'latest_price' => $gainInfo['latest_price'],
                        'gain_pct' => $gainInfo['gain_pct'],
                    ]);
                }
            } catch (\Throwable $e) {
                // Safeguard against individual API/calculation failures
            }
        }

        return $results->sortByDesc('gain_pct')->take($limit)->values();
    }

    /**
     * Latest available money flow snapshots per sector.
     *
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function moneyFlowSnapshots(): Collection
    {
        $hourly = SectorFlowSnapshot::query()
            ->latestForInterval(SectorFlowSnapshot::INTERVAL_HOURLY)
            ->get();

        $snapshots = $hourly->isNotEmpty()
            ? $hourly
            : SectorFlowSnapshot::query()->latestForInterval(SectorFlowSnapshot::INTERVAL_EOD)->get();

        if ($snapshots->isEmpty()) {
            $snapshots = SectorFlowSnapshot::query()
                ->orderByDesc('captured_at')
                ->get()
                ->unique('sector');
        }

        return $snapshots->filter(function (SectorFlowSnapshot $s) {
            return $s->daily_change_pct !== null || $s->hourly_change_pct !== null;
        })->values();
    }

    /**
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function moneyFlowGainers(int $limit = 2): Collection
    {
        return $this->moneyFlowSnapshots()
            ->sortByDesc(fn (SectorFlowSnapshot $s) => (float) ($s->daily_change_pct ?? $s->hourly_change_pct))
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function moneyFlowLosers(int $limit = 2): Collection
    {
        return $this->moneyFlowSnapshots()
            ->sortBy(fn (SectorFlowSnapshot $s) => (float) ($s->daily_change_pct ?? $s->hourly_change_pct))
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, EarningsEvent>
     */
    public function highestEpsSurprises(int $limit = 2): Collection
    {
        return EarningsEvent::query()
            ->whereNotNull('eps_surprise_percent')
            ->orderByDesc('eps_surprise_percent')
            ->orderByDesc('report_date')
            ->get()
            ->unique('symbol')
            ->take($limit)
            ->values();
    }
}
