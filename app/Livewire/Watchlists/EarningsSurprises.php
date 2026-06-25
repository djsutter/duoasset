<?php

namespace App\Livewire\Watchlists;

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Models\EarningsEvent;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EarningsSurprises extends Component
{
    use WithPagination;

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
    public bool $alertedOnly = true;

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

        DB::transaction(function () use ($event, $userId) {
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

            $stock = Stock::query()->where('symbol', $event->symbol)->first();

            if (! $stock) {
                $exchangeEnum = Exchange::tryFrom((string) ($event->exchange ?? '')) ?? Exchange::NYSE;
                $currencyEnum = match ($exchangeEnum) {
                    Exchange::TSX, Exchange::TSXV => Currency::CAD,
                    default => Currency::USD,
                };

                $sector = Sector::query()->orderBy('id')->first()
                    ?? Sector::create(['name' => 'Unknown']);
                $industry = Industry::query()->where('sector_id', $sector->id)->orderBy('id')->first()
                    ?? Industry::create(['name' => 'Unknown', 'sector_id' => $sector->id]);
                $subIndustry = SubIndustry::query()->where('industry_id', $industry->id)->orderBy('id')->first()
                    ?? SubIndustry::create(['name' => 'Unknown', 'industry_id' => $industry->id]);

                $stock = Stock::create([
                    'symbol' => $event->symbol,
                    'exchange' => $exchangeEnum,
                    'currency' => $currencyEnum,
                    'company_name' => $event->company_name ?? $event->symbol,
                    'sector_id' => $sector->id,
                    'industry_id' => $industry->id,
                    'sub_industry_id' => $subIndustry->id,
                ]);
            }

            $existing = WatchlistItem::query()
                ->where('watchlist_id', $watchlist->id)
                ->where('stock_id', $stock->id)
                ->first();

            if ($existing) {
                $this->flash = "Already watching {$event->symbol}.";

                return;
            }

            $note = sprintf(
                'Added from EPS surprise scanner: +%s%% EPS beat on %s.',
                number_format((float) ($event->eps_surprise_percent ?? 0), 2),
                optional($event->report_date)->toDateString() ?? '—',
            );

            WatchlistItem::create([
                'watchlist_id' => $watchlist->id,
                'stock_id' => $stock->id,
                'currency' => $stock->currency->value,
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
            ->with('alert')
            ->when($minPct !== null, fn ($q) => $q->where('eps_surprise_percent', '>=', $minPct))
            ->when($minMcap !== null, fn ($q) => $q->where('market_cap', '>=', $minMcap))
            ->when($this->exchange, fn ($q) => $q->where('exchange', $this->exchange))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('report_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('report_date', '<=', $this->dateTo))
            ->when($this->alertedOnly, fn ($q) => $q->whereHas('alert'))
            ->orderByDesc('detected_at');

        $events = $query->paginate(25);

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
