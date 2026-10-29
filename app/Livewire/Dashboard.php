<?php

namespace App\Livewire;

use App\Models\EarningsEvent;
use App\Models\SectorFlowSnapshot;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;

class Dashboard extends Component
{
    public string $viewMode = 'stocks';

    public function mount(): void
    {
        $this->syncViewMode();
    }

    #[On('view-mode-updated')]
    public function syncViewMode(?string $mode = null): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        $this->viewMode = $mode ?: ($user?->activeViewMode() ?? 'stocks');
    }

    /**
     * Resolve the current user's default watchlist, or the first watchlist if no default is marked.
     */
    public function resolveWatchlist(): ?Watchlist
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        return Watchlist::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->with(['items.stock'])
            ->first();
    }

    /**
     * Get the 2 highest scoring stocks from the resolved watchlist based on persisted StockBuySetupAlert scores.
     *
     * @return Collection<int, array{symbol: string, company_name: string, setup_score: int, setup_type: string, alert: StockBuySetupAlert}>
     */
    public function getHighestScoringStocks(Watchlist $watchlist): Collection
    {
        $symbols = $watchlist->items
            ->map(fn (WatchlistItem $item) => $item->stock?->symbol)
            ->filter()
            ->unique()
            ->values();

        if ($symbols->isEmpty()) {
            return collect();
        }

        $alerts = StockBuySetupAlert::query()
            ->whereIn('symbol', $symbols)
            ->orderByDesc('spike_date')
            ->orderByDesc('id')
            ->get();

        if ($alerts->isEmpty()) {
            return collect();
        }

        $stocksBySymbol = $watchlist->items
            ->filter(fn (WatchlistItem $i) => $i->stock !== null)
            ->keyBy(fn (WatchlistItem $i) => $i->stock->symbol);

        return $alerts->groupBy('symbol')
            ->map(function (Collection $symbolAlerts, string $symbol) use ($stocksBySymbol) {
                /** @var StockBuySetupAlert $bestAlert */
                $bestAlert = $symbolAlerts->sortByDesc('setup_score')->first();
                $stock = $stocksBySymbol->get($symbol)?->stock;

                return [
                    'symbol' => $symbol,
                    'company_name' => $bestAlert->company_name ?: ($stock?->company_name ?? $symbol),
                    'setup_score' => $bestAlert->setup_score,
                    'setup_type' => $bestAlert->setup_type,
                    'alert' => $bestAlert,
                ];
            })
            ->sortByDesc('setup_score')
            ->take(2)
            ->values();
    }

    /**
     * Get the 2 highest percentage gaining stocks since the date added to the resolved watchlist.
     *
     * @return Collection<int, array{symbol: string, company_name: string, added_at: \Illuminate\Support\Carbon, entry_price: float, latest_price: float, gain_pct: float}>
     */
    public function getHighestGainingStocks(Watchlist $watchlist, MarketDataProvider $provider): Collection
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

                    $gainPct = round((($latest - $entry) / $entry) * 100, 2);

                    return [
                        'entry_price' => $entry,
                        'latest_price' => $latest,
                        'gain_pct' => $gainPct,
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

        return $results->sortByDesc('gain_pct')->take(2)->values();
    }

    /**
     * Retrieve the latest available money flow snapshots per sector.
     *
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function getMoneyFlowSnapshots(): Collection
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
     * Get the top 2 money flow gainers in the past 24 hours.
     *
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function getMoneyFlowGainers(): Collection
    {
        return $this->getMoneyFlowSnapshots()
            ->sortByDesc(fn (SectorFlowSnapshot $s) => (float) ($s->daily_change_pct ?? $s->hourly_change_pct))
            ->take(2)
            ->values();
    }

    /**
     * Get the top 2 money flow losers in the past 24 hours.
     *
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function getMoneyFlowLosers(): Collection
    {
        return $this->getMoneyFlowSnapshots()
            ->sortBy(fn (SectorFlowSnapshot $s) => (float) ($s->daily_change_pct ?? $s->hourly_change_pct))
            ->take(2)
            ->values();
    }

    /**
     * Get the top 2 highest EPS surprise stocks.
     *
     * @return Collection<int, EarningsEvent>
     */
    public function getHighestEpsSurpriseStocks(): Collection
    {
        return EarningsEvent::query()
            ->whereNotNull('eps_surprise_percent')
            ->orderByDesc('eps_surprise_percent')
            ->orderByDesc('report_date')
            ->get()
            ->unique('symbol')
            ->take(2)
            ->values();
    }

    public function render(MarketDataProvider $marketDataProvider)
    {
        $watchlist = null;
        $hasWatchlistItems = false;
        $highestScoringStocks = collect();
        $highestGainingStocks = collect();
        $moneyFlowGainers = collect();
        $moneyFlowLosers = collect();
        $highestEpsSurprises = collect();

        if ($this->viewMode === 'stocks') {
            $watchlist = $this->resolveWatchlist();
            $hasWatchlistItems = $watchlist !== null && $watchlist->items->isNotEmpty();

            if ($hasWatchlistItems) {
                $highestScoringStocks = $this->getHighestScoringStocks($watchlist);
                $highestGainingStocks = $this->getHighestGainingStocks($watchlist, $marketDataProvider);
            }

            $moneyFlowGainers = $this->getMoneyFlowGainers();
            $moneyFlowLosers = $this->getMoneyFlowLosers();
            $highestEpsSurprises = $this->getHighestEpsSurpriseStocks();
        }

        return view('livewire.dashboard', [
            'viewMode' => $this->viewMode,
            'watchlist' => $watchlist,
            'hasWatchlistItems' => $hasWatchlistItems,
            'highestScoringStocks' => $highestScoringStocks,
            'highestGainingStocks' => $highestGainingStocks,
            'moneyFlowGainers' => $moneyFlowGainers,
            'moneyFlowLosers' => $moneyFlowLosers,
            'highestEpsSurprises' => $highestEpsSurprises,
        ]);
    }
}
