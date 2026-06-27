<?php

namespace App\Livewire\Watchlists;

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Enums\MoatLevel;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\AlphaVantageMarketDataProvider;
use App\Services\Stocks\StockProvisioner;
use App\Types\FiatMoney;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class Show extends Component
{
    public Watchlist $watchlist;

    // search / sort / filter state
    public string $search = '';

    public string $sort = 'symbol';

    public string $direction = 'asc';

    public ?string $filterExchange = null;

    public ?string $filterCurrency = null;

    public ?int $filterSectorId = null;

    public ?int $filterIndustryId = null;

    public ?int $filterSubIndustryId = null;

    public ?string $filterMoatLevel = null;

    // add-stock form state
    public bool $showAddForm = false;

    public string $symbol = '';

    public string $exchange = '';

    public string $currency = '';

    public string $company_name = '';

    public ?int $sector_id = null;

    public ?int $industry_id = null;

    public ?int $sub_industry_id = null;

    public string $moat_level = '';

    public ?string $target_price = null;

    public ?string $stop_price = null;

    public ?string $thesis = null;

    public ?string $notes = null;

    // --- Symbol autocomplete (Alpha Vantage SYMBOL_SEARCH) ---

    /** Text typed into the symbol field — drives the autocomplete. */
    public string $symbolQuery = '';

    /**
     * Cached list of matches from the last search call.
     *
     * @var array<int, array{symbol:string,name:string,region:string,currency:string,type:string,match_score:float}>
     */
    public array $searchResults = [];

    /** When true, the autocomplete panel is rendered. */
    public bool $showSearchResults = false;

    /** Surfaces "no results" UI without leaving the panel closed. */
    public bool $searchAttempted = false;

    /** Track that the user actually picked a symbol from the dropdown. */
    public bool $symbolSelected = false;

    public function mount(Watchlist $watchlist): void
    {
        $this->authorize('view', $watchlist);
        $this->watchlist = $watchlist;
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }
    }

    public function rules(): array
    {
        // Only four fields are strictly required: symbol, company name,
        // exchange, currency. Sector/industry/sub-industry/moat are
        // optional and fall back to sensible defaults via StockProvisioner
        // and MoatLevel::Medium so the user can add a stock with minimal
        // input (e.g. straight from the Alpha Vantage autocomplete).
        return [
            'symbol' => ['required', 'string', 'max:20'],
            'exchange' => ['required', 'string', 'in:'.implode(',', array_column(Exchange::cases(), 'value'))],
            'currency' => ['required', 'string', 'in:'.implode(',', array_column(Currency::cases(), 'value'))],
            'company_name' => ['required', 'string', 'max:255'],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'industry_id' => ['nullable', 'integer', 'exists:industries,id'],
            'sub_industry_id' => ['nullable', 'integer', 'exists:sub_industries,id'],
            'moat_level' => ['nullable', 'string', 'in:'.implode(',', array_column(MoatLevel::cases(), 'value'))],
            'target_price' => ['nullable', 'numeric', 'gt:0'],
            'stop_price' => ['nullable', 'numeric', 'gt:0'],
            'thesis' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Live autocomplete: called whenever `symbolQuery` updates. Hits Alpha
     * Vantage's SYMBOL_SEARCH endpoint once the user has typed at least 3
     * characters. The lookup is cached for 6 hours per query inside the
     * provider, so repeated keystrokes do not burn the free-tier daily
     * quota.
     */
    public function updatedSymbolQuery(string $value): void
    {
        // Typing in the symbol box invalidates a previous selection.
        $this->symbolSelected = false;
        $this->symbol = strtoupper(trim($value));

        $needle = trim($value);
        if (mb_strlen($needle) < 3) {
            $this->searchResults = [];
            $this->showSearchResults = false;
            $this->searchAttempted = false;

            return;
        }

        $provider = $this->resolveAlphaVantage();
        $this->searchAttempted = true;

        if (! $provider) {
            $this->searchResults = [];
            $this->showSearchResults = true;

            return;
        }

        $this->searchResults = $provider->searchSymbols($needle);
        $this->showSearchResults = true;
    }

    /**
     * Pick a row from the autocomplete dropdown. Populates company_name
     * and currency from Alpha Vantage's OVERVIEW endpoint when available,
     * and falls back to SYMBOL_SEARCH's currency (always present on the
     * match row) when OVERVIEW is unreachable. Exchange is best-effort —
     * Alpha Vantage's region is a country, not a venue, so we leave the
     * exchange field empty when we cannot map it cleanly and the user
     * fills it from the dropdown.
     */
    public function selectSymbol(int $index): void
    {
        $row = $this->searchResults[$index] ?? null;
        if (! is_array($row)) {
            return;
        }

        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($symbol === '') {
            return;
        }

        $this->symbol = $symbol;
        $this->symbolQuery = $symbol;
        $this->symbolSelected = true;
        $this->showSearchResults = false;

        // Pre-fill company name from the search row immediately so the UI
        // reflects the choice even before OVERVIEW resolves.
        if (! empty($row['name'])) {
            $this->company_name = (string) $row['name'];
        }

        // Best-effort currency from the SYMBOL_SEARCH result (always
        // present in Alpha Vantage's response).
        if (! empty($row['currency'])) {
            $this->currency = (string) $row['currency'];
        }

        // OVERVIEW gives us the canonical company name, exchange and
        // currency. It silently returns null on free-tier throttle, in
        // which case we keep whatever the SYMBOL_SEARCH row provided.
        $provider = $this->resolveAlphaVantage();
        if ($provider) {
            $overview = $provider->lookupOverview($symbol);
            if (is_array($overview)) {
                if (! empty($overview['name'])) {
                    $this->company_name = (string) $overview['name'];
                }
                if (! empty($overview['currency'])) {
                    $this->currency = (string) $overview['currency'];
                }
                if (! empty($overview['exchange'])) {
                    // Alpha Vantage uses values like "NYSE", "NASDAQ" that
                    // line up with our Exchange enum. Anything else
                    // (e.g. "OTC") we leave blank for the user to pick.
                    $mapped = Exchange::tryFrom($overview['exchange']);
                    if ($mapped) {
                        $this->exchange = $mapped->value;
                    }
                }
            }
        }

        // Currency is a required field. If Alpha Vantage gave us nothing,
        // the form's required validation will surface the missing field
        // to the user — exactly as the spec asks.
    }

    public function closeSearchResults(): void
    {
        $this->showSearchResults = false;
    }

    /**
     * Resolve the configured Alpha Vantage provider, returning null when
     * the active provider is something else (e.g. the Null provider when
     * no API key is configured). Keeps the autocomplete a no-op rather
     * than crashing in environments without an AV key.
     */
    private function resolveAlphaVantage(): ?AlphaVantageMarketDataProvider
    {
        try {
            $provider = app(\App\Services\MarketData\MarketDataProviderInterface::class);
        } catch (\Throwable) {
            return null;
        }

        return $provider instanceof AlphaVantageMarketDataProvider ? $provider : null;
    }

    public function openAddForm(): void
    {
        $this->resetAddForm();
        $this->showAddForm = true;
    }

    public function cancelAddForm(): void
    {
        $this->resetAddForm();
    }

    private function resetAddForm(): void
    {
        $this->showAddForm = false;
        $this->symbol = '';
        $this->symbolQuery = '';
        $this->searchResults = [];
        $this->showSearchResults = false;
        $this->searchAttempted = false;
        $this->symbolSelected = false;
        $this->exchange = '';
        $this->currency = '';
        $this->company_name = '';
        $this->sector_id = null;
        $this->industry_id = null;
        $this->sub_industry_id = null;
        $this->moat_level = '';
        $this->target_price = null;
        $this->stop_price = null;
        $this->thesis = null;
        $this->notes = null;
        $this->resetErrorBag();
    }

    public function addStock(StockProvisioner $provisioner): void
    {
        $this->authorize('update', $this->watchlist);
        $data = $this->validate();

        $symbol = strtoupper(trim($data['symbol']));

        // If the user filled the full taxonomy, honour it. Otherwise fall
        // back to StockProvisioner which guarantees a valid Stock row with
        // an "Unknown" sector/industry/sub-industry chain — keeping these
        // fields optional per the spec.
        $hasFullTaxonomy = ! empty($data['sector_id'])
            && ! empty($data['industry_id'])
            && ! empty($data['sub_industry_id']);

        if ($hasFullTaxonomy) {
            $stock = Stock::firstOrCreate(
                ['symbol' => $symbol, 'exchange' => $data['exchange']],
                [
                    'currency' => $data['currency'],
                    'company_name' => $data['company_name'],
                    'sector_id' => $data['sector_id'],
                    'industry_id' => $data['industry_id'],
                    'sub_industry_id' => $data['sub_industry_id'],
                ]
            );
        } else {
            $stock = $provisioner->findOrCreate($symbol, $data['exchange'], $data['company_name']);

            // The provisioner picks a default currency from the exchange.
            // If the user-supplied currency differs (e.g. AV reported USD
            // for a TSX dual-listing) update the existing row to reflect
            // the user's intent.
            if ($stock->currency->value !== $data['currency']) {
                $stock->currency = $data['currency'];
                $stock->save();
            }
        }

        if ($this->watchlist->items()->where('stock_id', $stock->id)->exists()) {
            $this->addError('symbol', __('This stock is already in the watchlist.'));

            return;
        }

        $item = new WatchlistItem([
            'watchlist_id' => $this->watchlist->id,
            'stock_id' => $stock->id,
            'thesis' => $data['thesis'] ?? null,
            'moat_level' => $data['moat_level'] ?: MoatLevel::Medium->value,
            'currency' => $stock->currency->value,
            'notes' => $data['notes'] ?? null,
        ]);

        // currency must be set before assigning FiatMoney casts
        if (! empty($data['target_price'])) {
            $item->target_price = FiatMoney::fromDecimal((string) $data['target_price'], $stock->currency->value);
        }
        if (! empty($data['stop_price'])) {
            $item->stop_price = FiatMoney::fromDecimal((string) $data['stop_price'], $stock->currency->value);
        }

        $item->save();

        session()->flash('status', __('Stock added to watchlist.'));
        $this->resetAddForm();
    }

    public function removeItem(int $itemId): void
    {
        $this->authorize('update', $this->watchlist);

        $item = WatchlistItem::where('watchlist_id', $this->watchlist->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $item->delete();
        session()->flash('status', __('Stock removed from watchlist.'));
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterExchange = null;
        $this->filterCurrency = null;
        $this->filterSectorId = null;
        $this->filterIndustryId = null;
        $this->filterSubIndustryId = null;
        $this->filterMoatLevel = null;
    }

    public function render()
    {
        $items = $this->buildQuery()->get();

        return view('livewire.watchlists.show', [
            'items' => $items,
            'sectors' => Sector::orderBy('sort_order')->orderBy('name')->get(),
            'industries' => Industry::orderBy('sort_order')->orderBy('name')->get(),
            'subIndustries' => SubIndustry::orderBy('sort_order')->orderBy('name')->get(),
            'exchanges' => Exchange::cases(),
            'currencies' => Currency::cases(),
            'moatLevels' => MoatLevel::cases(),
        ]);
    }

    private function buildQuery(): Builder
    {
        $q = WatchlistItem::query()
            ->with(['stock.sector', 'stock.industry', 'stock.subIndustry'])
            ->where('watchlist_id', $this->watchlist->id)
            ->join('stocks', 'watchlist_items.stock_id', '=', 'stocks.id')
            ->select('watchlist_items.*');

        if ($this->search !== '') {
            $needle = '%'.$this->search.'%';
            $q->where(function ($q) use ($needle) {
                $q->where('stocks.symbol', 'like', $needle)
                    ->orWhere('stocks.company_name', 'like', $needle);
            });
        }

        if ($this->filterExchange) {
            $q->where('stocks.exchange', $this->filterExchange);
        }
        if ($this->filterCurrency) {
            $q->where('stocks.currency', $this->filterCurrency);
        }
        if ($this->filterSectorId) {
            $q->where('stocks.sector_id', $this->filterSectorId);
        }
        if ($this->filterIndustryId) {
            $q->where('stocks.industry_id', $this->filterIndustryId);
        }
        if ($this->filterSubIndustryId) {
            $q->where('stocks.sub_industry_id', $this->filterSubIndustryId);
        }
        if ($this->filterMoatLevel) {
            $q->where('watchlist_items.moat_level', $this->filterMoatLevel);
        }

        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        match ($this->sort) {
            'company_name' => $q->orderBy('stocks.company_name', $direction),
            'sector' => $q->leftJoin('sectors', 'stocks.sector_id', '=', 'sectors.id')
                ->orderBy('sectors.name', $direction),
            'industry' => $q->leftJoin('industries', 'stocks.industry_id', '=', 'industries.id')
                ->orderBy('industries.name', $direction),
            'sub_industry' => $q->leftJoin('sub_industries', 'stocks.sub_industry_id', '=', 'sub_industries.id')
                ->orderBy('sub_industries.name', $direction),
            'moat_level' => $q->orderBy('watchlist_items.moat_level', $direction),
            'target_price' => $q->orderBy('watchlist_items.target_price', $direction),
            'stop_price' => $q->orderBy('watchlist_items.stop_price', $direction),
            default => $q->orderBy('stocks.symbol', $direction),
        };

        return $q;
    }
}
