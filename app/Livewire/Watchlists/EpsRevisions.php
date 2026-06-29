<?php

namespace App\Livewire\Watchlists;

use App\Enums\MoatLevel;
use App\Models\EpsRevisionAlert;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Stocks\StockProvisioner;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EpsRevisions extends Component
{
    use WithPagination;

    #[Url(as: 'min_pct')]
    public ?string $minRevisionPercent = null;

    #[Url(as: 'min_mcap')]
    public ?string $minMarketCap = null;

    #[Url(as: 'exchange')]
    public ?string $exchange = null;

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    /** "both" | "positive" | "negative" */
    #[Url(as: 'dir')]
    public string $direction = 'both';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->minRevisionPercent ??= (string) config('market_data.revision_scanner.positive_threshold', 20);
        $this->minMarketCap ??= (string) config('market_data.revision_scanner.min_market_cap', 100_000_000);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->minRevisionPercent = (string) config('market_data.revision_scanner.positive_threshold', 20);
        $this->minMarketCap = (string) config('market_data.revision_scanner.min_market_cap', 100_000_000);
        $this->exchange = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->direction = 'both';
        $this->resetPage();
    }

    public function addToWatchlist(int $alertId): void
    {
        $alert = EpsRevisionAlert::findOrFail($alertId);
        $userId = auth()->id();

        if (! $userId) {
            $this->flash = 'You must be signed in.';

            return;
        }

        $stocks = app(StockProvisioner::class);

        DB::transaction(function () use ($alert, $userId, $stocks) {
            $watchlist = Watchlist::query()
                ->where('user_id', $userId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if (! $watchlist) {
                $watchlist = Watchlist::create([
                    'user_id' => $userId,
                    'name' => 'EPS Revisions',
                    'description' => 'Auto-created from EPS revision scanner.',
                    'is_default' => true,
                ]);
            }

            $stock = $stocks->findOrCreate($alert->symbol, $alert->exchange, $alert->company_name);

            $existing = WatchlistItem::query()
                ->where('watchlist_id', $watchlist->id)
                ->where('stock_id', $stock->id)
                ->first();

            if ($existing) {
                $this->flash = "Already watching {$alert->symbol}.";

                return;
            }

            $pct = (float) $alert->revision_percent;
            $sign = $pct >= 0 ? '+' : '';
            $verb = $pct >= 0 ? 'raised' : 'cut';
            $period = optional($alert->next_quarter_end_date)->toDateString() ?? '—';

            WatchlistItem::create([
                'watchlist_id' => $watchlist->id,
                'stock_id' => $stock->id,
                'currency' => $stock->currency->value,
                'moat_level' => MoatLevel::Medium->value,
                'notes' => "Added from EPS revision scanner: target {$verb} {$sign}".number_format($pct, 2)."% for {$period}.",
            ]);

            $this->flash = "Added {$alert->symbol} to {$watchlist->name}.";
        });
    }

    public function render()
    {
        $minPct = is_numeric($this->minRevisionPercent) ? (float) $this->minRevisionPercent : null;
        $minMcap = is_numeric($this->minMarketCap) ? (int) $this->minMarketCap : null;

        $query = EpsRevisionAlert::query()
            ->when($minPct !== null, fn ($q) => $q->whereRaw('ABS(revision_percent) >= ?', [$minPct]))
            ->when($minMcap !== null, function ($q) use ($minMcap) {
                $q->where(function ($q) use ($minMcap) {
                    $q->whereNull('market_cap')
                      ->orWhere('market_cap', '>=', $minMcap);
                });
            })
            ->when($this->exchange, fn ($q) => $q->where('exchange', $this->exchange))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('detected_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('detected_at', '<=', $this->dateTo))
            ->when($this->direction !== 'both', fn ($q) => $q->where('direction', $this->direction));

        $alerts = $query->orderByDesc('detected_at')->paginate(25);

        $watched = collect();
        if (auth()->check()) {
            $watched = DB::table('watchlist_items')
                ->join('watchlists', 'watchlist_items.watchlist_id', '=', 'watchlists.id')
                ->join('stocks', 'watchlist_items.stock_id', '=', 'stocks.id')
                ->where('watchlists.user_id', auth()->id())
                ->pluck('stocks.symbol')
                ->map(fn ($s) => strtoupper((string) $s));
        }

        return view('livewire.watchlists.eps-revisions', [
            'alerts' => $alerts,
            'watched' => $watched,
            'exchanges' => config('market_data.revision_scanner.exchanges', []),
        ]);
    }
}
