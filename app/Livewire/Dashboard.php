<?php

namespace App\Livewire;

use App\Models\EarningsEvent;
use App\Models\SectorFlowSnapshot;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\Auth\AuthorizationResolver;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\Dashboard\RecentFuturesService;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();
        $permitted = $resolver->permittedContexts($user);

        if ($mode !== null && in_array(strtolower($mode), $permitted, true)) {
            $this->viewMode = strtolower($mode);

            return;
        }

        $this->viewMode = $resolver->resolveActiveContext($user) ?? 'stocks';
    }

    public function refreshFuturesQuotes(): void
    {
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();

        if (! $resolver->canAccessFutures($user)) {
            abort(403, 'Access denied: Futures Subscriber role required.');
        }

        $this->viewMode = 'futures';
    }

    /**
     * Resolve the current user's default watchlist, or the first watchlist if no default is marked.
     */
    public function resolveWatchlist(): ?Watchlist
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || $user->isAnonymousUser()) {
            return null;
        }

        $watchlist = Watchlist::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->with(['items.stock'])
            ->first();

        if ($watchlist) {
            return $watchlist;
        }

        return Watchlist::query()
            ->where('is_global', true)
            ->orderBy('id')
            ->with(['items.stock'])
            ->first();
    }

    /**
     * Get the 2 highest scoring setups market-wide (latest alert per symbol/setup type), cached until the next setup scan.
     *
     * @return Collection<int, array{symbol: string, company_name: string, setup_score: int, setup_type: string, alert: StockBuySetupAlert}>
     */
    public function getTopScoringSetups(): Collection
    {
        return app(DashboardCacheService::class)->topScoringSetups();
    }

    /**
     * Get the 2 highest percentage gaining stocks since the date added to the resolved watchlist (cached per user).
     *
     * @return Collection<int, array{symbol: string, company_name: string, added_at: \Illuminate\Support\Carbon, entry_price: float, latest_price: float, gain_pct: float}>
     */
    public function getHighestGainingStocks(Watchlist $watchlist, MarketDataProvider $provider): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || $user->isAnonymousUser()) {
            return collect();
        }

        return app(DashboardCacheService::class)->userGainers($user, $watchlist, $provider);
    }

    /**
     * Get the top 2 money flow gainers in the past 24 hours.
     *
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function getMoneyFlowGainers(): Collection
    {
        return app(DashboardCacheService::class)->moneyFlowGainers(Auth::user());
    }

    /**
     * Get the top 2 money flow losers in the past 24 hours.
     *
     * @return Collection<int, SectorFlowSnapshot>
     */
    public function getMoneyFlowLosers(): Collection
    {
        return app(DashboardCacheService::class)->moneyFlowLosers(Auth::user());
    }

    /**
     * Get the top 2 highest EPS surprise stocks (cached until the next EPS surprises run).
     *
     * @return Collection<int, EarningsEvent>
     */
    public function getHighestEpsSurpriseStocks(): Collection
    {
        return app(DashboardCacheService::class)->highestEpsSurprises();
    }

    public function render(MarketDataProvider $marketDataProvider)
    {
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();
        $permitted = $resolver->permittedContexts($user);

        if (! in_array($this->viewMode, $permitted, true)) {
            $this->viewMode = $resolver->resolveActiveContext($user) ?? 'stocks';
        }

        $watchlist = null;
        $hasWatchlistItems = false;
        $highestScoringStocks = collect();
        $highestGainingStocks = collect();
        $moneyFlowGainers = collect();
        $moneyFlowLosers = collect();
        $highestEpsSurprises = collect();
        $recentFutures = collect();
        $supportedFutures = [];
        $snapshotMeta = [];

        $cacheService = app(DashboardCacheService::class);

        if ($this->viewMode === 'futures') {
            if (! $resolver->canAccessFutures($user)) {
                abort(403, 'Access denied: Futures Subscriber role required.');
            }

            $recentFuturesService = app(RecentFuturesService::class);
            $recentFutures = $recentFuturesService->getRecentFuturesWithQuotes($user);
            $supportedFutures = $recentFuturesService->getSupportedInstrumentsCatalog();
        } elseif ($this->viewMode === 'stocks') {
            $watchlist = $this->resolveWatchlist();
            $hasWatchlistItems = $watchlist !== null && $watchlist->items->isNotEmpty();

            if ($hasWatchlistItems) {
                $highestGainingStocks = $this->getHighestGainingStocks($watchlist, $marketDataProvider);
            }

            $highestScoringStocks = $this->getTopScoringSetups();
            $moneyFlowGainers = $this->getMoneyFlowGainers();
            $moneyFlowLosers = $this->getMoneyFlowLosers();
            $highestEpsSurprises = $this->getHighestEpsSurpriseStocks();

            if ($user && ! $user->isAnonymousUser()) {
                $cacheService->triggerPrefetchIfStale($user);
            }

            $snapshotMeta = $cacheService->getSnapshotMetadata('stocks');
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
            'recentFutures' => $recentFutures,
            'supportedFutures' => $supportedFutures,
            'snapshotMeta' => $snapshotMeta,
        ]);
    }
}
