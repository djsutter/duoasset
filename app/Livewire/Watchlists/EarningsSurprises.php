<?php

namespace App\Livewire\Watchlists;

use App\Enums\MoatLevel;
use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Stocks\StockProvisioner;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EarningsSurprises extends Component
{
    use WithPagination;

    /** Minimum absolute surprise magnitude (e.g. 30 matches both +30% and -30%). */
    #[Url(as: 'min_pct')]
    public ?string $minSurprisePercent = null;

    #[Url(as: 'min_mcap')]
    public ?string $minMarketCap = null;

    #[Url(as: 'exchange')]
    public ?string $exchange = null;

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    #[Url(as: 'alerted')]
    public bool $alertedOnly = false;

    /** "both" | "positive" | "negative" */
    #[Url(as: 'dir')]
    public string $direction = 'both';

    #[Url(as: 'sort')]
    public string $sortField = 'detected_at';

    #[Url(as: 'sort_dir')]
    public string $sortDirection = 'desc';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->minSurprisePercent ??= (string) config('market_data.earnings_scanner.min_eps_surprise_percent', 90);
        $this->minMarketCap ??= (string) config('market_data.earnings_scanner.min_market_cap', 100_000_000);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->minSurprisePercent = (string) config('market_data.earnings_scanner.min_eps_surprise_percent', 90);
        $this->minMarketCap = (string) config('market_data.earnings_scanner.min_market_cap', 100_000_000);
        $this->exchange = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->alertedOnly = true;
        $this->direction = 'both';
        $this->sortField = 'detected_at';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'desc';
        }

        $this->resetPage();
    }

    public function addToWatchlist(int $eventId): void
    {
        $event = EarningsEvent::findOrFail($eventId);
        $userId = auth()->id();

        if (! $userId) {
            $this->flash = 'You must be signed in.';

            return;
        }

        $stocks = app(StockProvisioner::class);

        DB::transaction(function () use ($event, $userId, $stocks) {
            $watchlist = Watchlist::query()
                ->where('user_id', $userId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if (! $watchlist) {
                $watchlist = Watchlist::create([
                    'user_id' => $userId,
                    'name' => 'Earnings Surprises',
                    'description' => 'Auto-created from EPS surprise scanner.',
                    'is_default' => true,
                ]);
            }

            $stock = $stocks->findOrCreate(
                $event->symbol,
                $event->exchange,
                $event->company_name,
            );

            $existing = WatchlistItem::query()
                ->where('watchlist_id', $watchlist->id)
                ->where('stock_id', $stock->id)
                ->first();

            if ($existing) {
                $this->flash = "Already watching {$event->symbol}.";

                return;
            }

            $pct = (float) ($event->eps_surprise_percent ?? 0);
            $sign = $pct >= 0 ? '+' : '';
            $kind = $pct >= 0 ? 'beat' : 'miss';
            $note = sprintf(
                'Added from EPS surprise scanner: %s%s%% EPS %s on %s.',
                $sign,
                number_format($pct, 2),
                $kind,
                optional($event->report_date)->toDateString() ?? '—',
            );

            WatchlistItem::create([
                'watchlist_id' => $watchlist->id,
                'stock_id' => $stock->id,
                'currency' => $stock->currency->value,
                'moat_level' => MoatLevel::Medium->value,
                'notes' => $note,
            ]);

            $this->flash = "Added {$event->symbol} to {$watchlist->name}.";
        });
    }

    public function render()
    {
        $minPct = is_numeric($this->minSurprisePercent) ? (float) $this->minSurprisePercent : null;
        $minMcap = is_numeric($this->minMarketCap) ? (int) $this->minMarketCap : null;

        $query = EarningsEvent::query()
            ->with('alerts')
            ->when($minPct !== null, function ($q) use ($minPct) {
                // Match by ABSOLUTE magnitude so the same threshold catches
                // big beats and big misses.
                $q->whereRaw('ABS(eps_surprise_percent) >= ?', [$minPct]);
            })
            ->when($minMcap !== null, fn ($q) => $q->where(function ($qq) use ($minMcap) {
                // Include un-enriched rows (market_cap NULL) so they don't
                // silently disappear from the UI before the queue worker
                // has had a chance to fill them in.
                $qq->whereNull('market_cap')->orWhere('market_cap', '>=', $minMcap);
            }))
            ->when($this->exchange, fn ($q) => $q->where(function ($qq) {
                $exch = $this->exchange;
                $qq->whereNull('exchange')->orWhere('exchange', $exch);
            }))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('report_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('report_date', '<=', $this->dateTo));

        // Direction filter — applied on the alert relation when "alerted only",
        // otherwise on the event's eps_surprise_percent sign.
        if ($this->direction === 'positive') {
            $query->when(
                $this->alertedOnly,
                fn ($q) => $q->whereHas('alerts', fn ($a) => $a->where('direction', EarningsAlert::DIRECTION_POSITIVE)),
                fn ($q) => $q->where('eps_surprise_percent', '>=', 0),
            );
        } elseif ($this->direction === 'negative') {
            $query->when(
                $this->alertedOnly,
                fn ($q) => $q->whereHas('alerts', fn ($a) => $a->where('direction', EarningsAlert::DIRECTION_NEGATIVE)),
                fn ($q) => $q->where('eps_surprise_percent', '<', 0),
            );
        } else {
            $query->when($this->alertedOnly, fn ($q) => $q->whereHas('alerts'));
        }

        $events = $query->orderBy($this->sortField, $this->sortDirection)
            ->orderByDesc('detected_at')
            ->paginate(25);

        // Watchlisted symbol set for the current user (for "Already watched" badge).
        $watched = collect();
        if (auth()->check()) {
            $watched = DB::table('watchlist_items')
                ->join('watchlists', 'watchlist_items.watchlist_id', '=', 'watchlists.id')
                ->join('stocks', 'watchlist_items.stock_id', '=', 'stocks.id')
                ->where('watchlists.user_id', auth()->id())
                ->pluck('stocks.symbol')
                ->map(fn ($s) => strtoupper((string) $s));
        }

        return view('livewire.watchlists.earnings-surprises', [
            'events' => $events,
            'watched' => $watched,
            'exchanges' => config('market_data.earnings_scanner.exchanges', []),
        ]);
    }
}
