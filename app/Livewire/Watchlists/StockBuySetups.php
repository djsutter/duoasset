<?php

namespace App\Livewire\Watchlists;

use App\Enums\MoatLevel;
use App\Models\StockBuySetupAlert;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class StockBuySetups extends Component
{
    use WithPagination;

    #[Url(as: 'type')]
    public ?string $setupType = null;

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

    #[Url(as: 'symbol')]
    public ?string $symbol = null;

    #[Url(as: 'company')]
    public ?string $company = null;

    #[Url(as: 'sort')]
    public string $sortBy = 'setup_score';

    #[Url(as: 'dir')]
    public string $sortDirection = 'desc';

    public ?string $flash = null;

    public bool $configModalOpen = false;

    public string $configTab = 'types';

    public array $configState = [];

    public string $selectedConfigSetupType = 'heartbeat_consolidation_spike';

    public string $newSetupTypeKey = '';

    public string $newSetupTypeLabel = '';

    public ?string $configFlash = null;

    public function mount(): void
    {
        $this->minScore ??= (string) app(BuySetupConfigService::class)->getMinSetupScore();
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->setupType = null;
        $this->minScore = (string) app(BuySetupConfigService::class)->getMinSetupScore();
        $this->minMarketCap = null;
        $this->exchange = null;
        $this->marketCapCategory = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->unwatchedOnly = false;
        $this->symbol = null;
        $this->company = null;
        $this->sortBy = 'setup_score';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function openConfigModal(): void
    {
        $configService = app(BuySetupConfigService::class);
        $this->configState = $configService->getConfig();
        $this->configState['exchanges_text'] = implode(', ', (array) ($this->configState['exchanges'] ?? []));
        $this->configState['benchmark_symbols_text'] = implode(', ', (array) ($this->configState['benchmark_symbols'] ?? []));

        $types = array_keys($this->configState['setup_types'] ?? []);
        if (! in_array($this->selectedConfigSetupType, $types, true)) {
            $this->selectedConfigSetupType = $types[0] ?? 'heartbeat_consolidation_spike';
        }

        $this->configFlash = null;
        $this->newSetupTypeKey = '';
        $this->newSetupTypeLabel = '';
        $this->configModalOpen = true;
    }

    public function closeConfigModal(): void
    {
        $this->configModalOpen = false;
        $this->configFlash = null;
    }

    public function selectConfigSetupType(string $key): void
    {
        if (isset($this->configState['setup_types'][$key])) {
            $this->selectedConfigSetupType = $key;
            $this->configFlash = null;
        }
    }

    public function updatedSelectedConfigSetupType(): void
    {
        $this->configFlash = null;
    }

    public function addSetupType(): void
    {
        $this->validate([
            'newSetupTypeKey' => 'required|string|min:2|max:50',
            'newSetupTypeLabel' => 'required|string|min:2|max:100',
        ]);

        $key = Str::slug($this->newSetupTypeKey, '_');

        if (isset($this->configState['setup_types'][$key])) {
            $this->addError('newSetupTypeKey', 'This setup type key already exists.');

            return;
        }

        $configService = app(BuySetupConfigService::class);
        $newType = $configService->createDefaultSetupType($key, $this->newSetupTypeLabel);

        $this->configState['setup_types'][$key] = $newType;
        $this->selectedConfigSetupType = $key;
        $this->newSetupTypeKey = '';
        $this->newSetupTypeLabel = '';
        $this->configFlash = "Setup type '{$newType['label']}' added.";
    }

    public function removeSetupType(string $key): void
    {
        if ($key === 'heartbeat_consolidation_spike') {
            $this->configFlash = 'The default setup type cannot be deleted.';

            return;
        }

        unset($this->configState['setup_types'][$key]);
        $types = array_keys($this->configState['setup_types'] ?? []);
        $this->selectedConfigSetupType = $types[0] ?? 'heartbeat_consolidation_spike';
        $this->configFlash = 'Setup type removed.';
    }

    public function saveConfig(): void
    {
        $configService = app(BuySetupConfigService::class);

        if (isset($this->configState['exchanges_text'])) {
            $this->configState['exchanges'] = array_values(array_filter(array_map('trim', explode(',', (string) $this->configState['exchanges_text']))));
        }
        if (isset($this->configState['benchmark_symbols_text'])) {
            $this->configState['benchmark_symbols'] = array_values(array_filter(array_map('trim', explode(',', (string) $this->configState['benchmark_symbols_text']))));
        }

        $saved = $configService->saveConfig($this->configState);
        $this->configState = $saved;
        $this->configState['exchanges_text'] = implode(', ', (array) ($this->configState['exchanges'] ?? []));
        $this->configState['benchmark_symbols_text'] = implode(', ', (array) ($this->configState['benchmark_symbols'] ?? []));

        $this->configFlash = 'Buy setup configuration saved successfully.';
        $this->flash = 'Buy setup configuration updated.';
    }

    public function resetConfigToDefaults(): void
    {
        $configService = app(BuySetupConfigService::class);
        $defaults = $configService->resetToDefaults();
        $this->configState = $defaults;
        $this->configState['exchanges_text'] = implode(', ', (array) ($this->configState['exchanges'] ?? []));
        $this->configState['benchmark_symbols_text'] = implode(', ', (array) ($this->configState['benchmark_symbols'] ?? []));
        $this->selectedConfigSetupType = 'heartbeat_consolidation_spike';
        $this->configFlash = 'Configuration reset to default values.';
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['symbol', 'company_name', 'setup_score', 'heartbeat_score', 'detected_at', 'spike_date'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }

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
                'notes' => 'Buy setup ['.$alert->setup_type.'] ('.$alert->setup_score.'/100): '.$alert->reason_summary,
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
            ->when($this->symbol, fn ($q) => $q->where('symbol', 'like', $this->symbol.'%'))
            ->when($this->company, fn ($q) => $q->where('company_name', 'like', '%'.$this->company.'%'))
            ->when($this->setupType, fn ($q) => $q->where('setup_type', $this->setupType))
            ->when($minScore !== null, fn ($q) => $q->where('setup_score', '>=', $minScore))
            ->when($minMcap !== null, fn ($q) => $q->where('market_cap', '>=', $minMcap))
            ->when($this->exchange, fn ($q) => $q->where('exchange', $this->exchange))
            ->when($this->marketCapCategory, fn ($q) => $q->where('market_cap_category', $this->marketCapCategory))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('detected_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('detected_at', '<=', $this->dateTo))
            ->when($this->unwatchedOnly && $watchedSymbols->isNotEmpty(),
                fn ($q) => $q->whereNotIn('symbol', $watchedSymbols->all()));

        $sortBy = in_array($this->sortBy, ['symbol', 'company_name', 'setup_score', 'heartbeat_score', 'detected_at', 'spike_date'], true) ? $this->sortBy : 'setup_score';
        $direction = strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';

        $alerts = $query
            ->orderBy($sortBy, $direction)
            ->orderByDesc('detected_at')
            ->paginate(25);

        $scorer = app(StockBuySetupScorer::class);
        $scoreBreakdowns = $alerts->getCollection()
            ->mapWithKeys(fn (StockBuySetupAlert $alert) => [$alert->id => $scorer->breakdown($alert, $alert->setup_type)]);

        $configService = app(BuySetupConfigService::class);

        return view('livewire.watchlists.stock-buy-setups', [
            'alerts' => $alerts,
            'watched' => $watchedSymbols,
            'exchanges' => $configService->getExchanges(),
            'setupTypes' => $this->setupTypes(),
            'scoreBreakdowns' => $scoreBreakdowns,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function setupTypes(): array
    {
        $configured = app(BuySetupConfigService::class)->getSetupTypes();

        return collect($configured)
            ->filter(fn (array $type) => (bool) ($type['enabled'] ?? false))
            ->mapWithKeys(fn (array $type, string $key) => [$key => (string) ($type['label'] ?? $key)])
            ->all();
    }
}
