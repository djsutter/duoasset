<?php

namespace App\Livewire\Stocks;

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $sort = 'symbol';

    public string $direction = 'asc';

    public ?string $filterExchange = null;

    public ?string $filterCurrency = null;

    public ?int $filterSectorId = null;

    public ?int $filterIndustryId = null;

    public ?int $filterSubIndustryId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'sort' => ['except' => 'symbol'],
        'direction' => ['except' => 'asc'],
        'filterExchange' => ['except' => null],
        'filterCurrency' => ['except' => null],
        'filterSectorId' => ['except' => null],
        'filterIndustryId' => ['except' => null],
        'filterSubIndustryId' => ['except' => null],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updating($name, $value): void
    {
        if (str_starts_with($name, 'filter')) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterExchange = null;
        $this->filterCurrency = null;
        $this->filterSectorId = null;
        $this->filterIndustryId = null;
        $this->filterSubIndustryId = null;
        $this->resetPage();
    }

    public function render()
    {
        $stocks = $this->buildQuery()->paginate(25);

        return view('livewire.stocks.index', [
            'stocks' => $stocks,
            'sectors' => Sector::orderBy('sort_order')->orderBy('name')->get(),
            'industries' => Industry::orderBy('sort_order')->orderBy('name')->get(),
            'subIndustries' => SubIndustry::orderBy('sort_order')->orderBy('name')->get(),
            'exchanges' => Exchange::cases(),
            'currencies' => Currency::cases(),
        ]);
    }

    private function buildQuery(): Builder
    {
        $q = Stock::query()->with(['sector', 'industry', 'subIndustry']);

        if ($this->search !== '') {
            $needle = '%'.$this->search.'%';
            $q->where(function ($q) use ($needle) {
                $q->where('symbol', 'like', $needle)
                    ->orWhere('company_name', 'like', $needle);
            });
        }

        if ($this->filterExchange) {
            $q->where('exchange', $this->filterExchange);
        }
        if ($this->filterCurrency) {
            $q->where('currency', $this->filterCurrency);
        }
        if ($this->filterSectorId) {
            $q->where('sector_id', $this->filterSectorId);
        }
        if ($this->filterIndustryId) {
            $q->where('industry_id', $this->filterIndustryId);
        }
        if ($this->filterSubIndustryId) {
            $q->where('sub_industry_id', $this->filterSubIndustryId);
        }

        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        match ($this->sort) {
            'company_name' => $q->orderBy('company_name', $direction),
            'exchange' => $q->orderBy('exchange', $direction),
            'currency' => $q->orderBy('currency', $direction),
            'sector' => $q->leftJoin('sectors', 'stocks.sector_id', '=', 'sectors.id')
                ->orderBy('sectors.name', $direction)
                ->select('stocks.*'),
            'industry' => $q->leftJoin('industries', 'stocks.industry_id', '=', 'industries.id')
                ->orderBy('industries.name', $direction)
                ->select('stocks.*'),
            'sub_industry' => $q->leftJoin('sub_industries', 'stocks.sub_industry_id', '=', 'sub_industries.id')
                ->orderBy('sub_industries.name', $direction)
                ->select('stocks.*'),
            'daily_change', 'change' => $q->orderBy('daily_change', $direction),
            'daily_change_percent', 'change_percent', 'change_pct' => $q->orderBy('daily_change_percent', $direction),
            'last_checked_at', 'checked' => $q->orderBy('last_checked_at', $direction),
            default => $q->orderBy('symbol', $direction),
        };

        return $q;
    }
}
