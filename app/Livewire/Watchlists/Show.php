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
        return [
            'symbol' => ['required', 'string', 'max:20'],
            'exchange' => ['required', 'string', 'in:'.implode(',', array_column(Exchange::cases(), 'value'))],
            'currency' => ['required', 'string', 'in:'.implode(',', array_column(Currency::cases(), 'value'))],
            'company_name' => ['required', 'string', 'max:255'],
            'sector_id' => ['required', 'integer', 'exists:sectors,id'],
            'industry_id' => ['required', 'integer', 'exists:industries,id'],
            'sub_industry_id' => ['required', 'integer', 'exists:sub_industries,id'],
            'moat_level' => ['required', 'string', 'in:'.implode(',', array_column(MoatLevel::cases(), 'value'))],
            'target_price' => ['nullable', 'numeric', 'gt:0'],
            'stop_price' => ['nullable', 'numeric', 'gt:0'],
            'thesis' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
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

    public function addStock(): void
    {
        $this->authorize('update', $this->watchlist);
        $data = $this->validate();

        $symbol = strtoupper(trim($data['symbol']));

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

        if ($this->watchlist->items()->where('stock_id', $stock->id)->exists()) {
            $this->addError('symbol', __('This stock is already in the watchlist.'));

            return;
        }

        $item = new WatchlistItem([
            'watchlist_id' => $this->watchlist->id,
            'stock_id' => $stock->id,
            'thesis' => $data['thesis'] ?? null,
            'moat_level' => $data['moat_level'],
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
