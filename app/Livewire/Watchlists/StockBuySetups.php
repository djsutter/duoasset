<?php

namespace App\Livewire\Watchlists;

use App\Enums\MoatLevel;
use App\Models\StockBuySetupAlert;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Stocks\StockProvisioner;
use App\Services\Stocks\StockBuySetupScorer;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class StockBuySetups extends Component
{
    use WithPagination;

    #[Url(as: 'min_score')]
    public ?string $minScore = null;

    #[Url(as: 'min_mcap')]
    public ?string $minMarketCap = null;

    #[Url(as: 'exchange')]
    public ?string $exchange = null;

    #[Url(as: 'market_cap_category')]
    public ?string $marketCapCategory = null;

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    #[Url(as: 'unwatched_only')]
    public bool $unwatchedOnly = false;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->minScore ??= (string) config('market_data.buy_setup_scanner.min_heartbeat_score', 50);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->minScore = (string) config('market_data.buy_setup_scanner.min_heartbeat_score', 50);
        $this->minMarketCap = null;
        $this->exchange = null;
        $this->marketCapCategory = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->unwatchedOnly = false;
        $this->resetPage();
    }

    public function addToWatchlist(int $alertId): void
    {
        $alert = StockBuySetupAlert::findOrFail($alertId);
        $userId = auth()->id();

        if (! $userId) {
            $this->flash = 'You must be signed in.';

            return;
        }

        $stocks = app(StockProvisioner::class);

        DB::transaction(function () use ($alert, $userId, $stocks) {
            $watchlist = Watchlist::firstOrCreate(
                ['user_id' => $userId, 'name' => 'Setup'],
                [
                    'description' => 'Auto-created from Stock Buy Setup scanner.',
                    'is_default' => false,
                ],
            );

            $stock = $stocks->findOrCreate($alert->symbol, $alert->exchange, $alert->company_name);

            $existing = WatchlistItem::query()
                ->where('watchlist_id', $watchlist->id)
                ->where('stock_id', $stock->id)
                ->first();

            if ($existing) {
                $this->flash = "Already watching {$alert->symbol}.";

                return;
            }

            WatchlistItem::create([
                'watchlist_id' => $watchlist->id,
                'stock_id' => $stock->id,
                'currency' => $stock->currency->value,
                'moat_level' => MoatLevel::Medium->value,
                'notes' => 'Buy setup ('.$alert->heartbeat_score.'/100): '.$alert->reason_summary,
            ]);

            $this->flash = "Added {$alert->symbol} to Setup watchlist.";
        });
    }

    public function render()
    {
        $minScore = is_numeric($this->minScore) ? (int) $this->minScore : null;
        $minMcap = is_numeric($this->minMarketCap) ? (int) $this->minMarketCap : null;

        $watchedSymbols = collect();
        if (auth()->check()) {
            $watchedSymbols = DB::table('watchlist_items')
                ->join('watchlists', 'watchlist_items.watchlist_id', '=', 'watchlists.id')
                ->join('stocks', 'watchlist_items.stock_id', '=', 'stocks.id')
                ->where('watchlists.user_id', auth()->id())
                ->pluck('stocks.symbol')
                ->map(fn ($s) => strtoupper((string) $s));
        }

        $query = StockBuySetupAlert::query()
            ->when($minScore !== null, fn ($q) => $q->where('heartbeat_score', '>=', $minScore))
            ->when($minMcap !== null, fn ($q) => $q->where('market_cap', '>=', $minMcap))
            ->when($this->exchange, fn ($q) => $q->where('exchange', $this->exchange))
            ->when($this->marketCapCategory, fn ($q) => $q->where('market_cap_category', $this->marketCapCategory))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('detected_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('detected_at', '<=', $this->dateTo))
            ->when($this->unwatchedOnly && $watchedSymbols->isNotEmpty(),
                fn ($q) => $q->whereNotIn('symbol', $watchedSymbols->all()));

        $alerts = $query
            ->orderByDesc('heartbeat_score')
            ->orderByDesc('detected_at')
            ->paginate(25);

        $scorer = app(StockBuySetupScorer::class);
        $scoreBreakdowns = $alerts->getCollection()
            ->mapWithKeys(fn (StockBuySetupAlert $alert) => [$alert->id => $scorer->breakdown($alert)]);

        return view('livewire.watchlists.stock-buy-setups', [
            'alerts' => $alerts,
            'watched' => $watchedSymbols,
            'exchanges' => config('market_data.buy_setup_scanner.exchanges', []),
            'scoreBreakdowns' => $scoreBreakdowns,
        ]);
    }
}
