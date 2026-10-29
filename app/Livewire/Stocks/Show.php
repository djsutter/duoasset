<?php

namespace App\Livewire\Stocks;

use App\Exceptions\MarketDataProviderException;
use App\Models\Stock;
use App\Models\StockBuySetupAlert;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockChartService;
use Carbon\Carbon;
use Livewire\Component;

class Show extends Component
{
    public string $symbol;

    public ?Stock $stock = null;

    public ?array $quote = null;

    public ?array $profile = null;

    public ?StockBuySetupAlert $latestSetupAlert = null;

    public ?StockBuySetupAlert $activeSetupAlert = null;

    public ?string $selectedSetupType = null;

    /**
     * @var \Illuminate\Support\Collection<int, StockBuySetupAlert>
     */
    public $setupAlertsByType;

    public array $setupScoreBreakdown = [];

    /**
     * @var array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public array $candles = [];

    public ?string $candleError = null;

    public ?string $priceType = null;

    public function mount(
        string $symbol,
        MarketDataProvider $provider,
        StockBuySetupScorer $scorer,
        StockChartService $chartService
    ): void {
        $this->symbol = strtoupper(trim($symbol));

        // Reject invalid symbols safely
        if (! preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $this->symbol)) {
            abort(404, 'Invalid stock symbol.');
        }

        // 1. Locate local stock record if present
        $this->stock = Stock::where('symbol', $this->symbol)->first();

        // 2. Fetch company profile and live/delayed quote from market data provider
        try {
            $this->profile = $provider->profile($this->symbol);
        } catch (\Throwable) {
            $this->profile = null;
        }

        try {
            $this->quote = $provider->quote($this->symbol);
        } catch (\Throwable) {
            $this->quote = null;
        }

        // 3. Retrieve latest persisted setup alert and alerts by setup type
        $this->loadSetupAlerts($scorer);

        // 4. Retrieve approximately 1 year of daily candlestick history
        try {
            $candleResponse = $chartService->getCandles($this->symbol, '1day');
            $this->candles = $candleResponse['candles'] ?? [];
        } catch (MarketDataProviderException $e) {
            $this->candles = [];
            $this->candleError = $e->getCode() === 429
                ? 'Market data rate limit reached. Please retry in a moment.'
                : 'Market data provider is currently unavailable. Please retry shortly.';
        } catch (\Throwable) {
            $this->candles = [];
            $this->candleError = 'Failed to load chart data.';
        }

        // Determine price type indicator
        if ($this->quote && isset($this->quote['price']) && is_numeric($this->quote['price'])) {
            $currency = $this->currencyCode;
            $this->priceType = "Market Quote ({$currency})";
        } elseif ($this->stock && $this->stock->last_price) {
            $this->priceType = 'Previous Close';
        } elseif (! empty($this->candles)) {
            $this->priceType = 'End-of-Day';
        } else {
            $this->priceType = null;
        }
    }

    public function getCurrencyCodeProperty(): string
    {
        return (string) ($this->profile['currency']
            ?? $this->quote['currency']
            ?? $this->stock?->currency?->currency_code
            ?? 'USD');
    }

    public function getCompanyNameProperty(): ?string
    {
        $name = $this->profile['company_name'] ?? $this->profile['name'] ?? null;
        if (! empty($name)) {
            return $name;
        }

        $quoteName = $this->quote['company_name'] ?? $this->quote['name'] ?? null;
        if (! empty($quoteName)) {
            return $quoteName;
        }

        if ($this->stock && ! empty($this->stock->company_name)) {
            return $this->stock->company_name;
        }

        $alert = $this->activeSetupAlert ?? $this->latestSetupAlert;
        if ($alert && ! empty($alert->company_name)) {
            return $alert->company_name;
        }

        return null;
    }

    public function getExchangeProperty(): ?string
    {
        $exchange = $this->profile['exchange'] ?? null;
        if (! empty($exchange)) {
            return $exchange;
        }

        $quoteEx = $this->quote['exchange'] ?? null;
        if (! empty($quoteEx)) {
            return $quoteEx;
        }

        if ($this->stock && $this->stock->exchange) {
            return $this->stock->exchange->value;
        }

        $alert = $this->activeSetupAlert ?? $this->latestSetupAlert;
        if ($alert && ! empty($alert->exchange)) {
            return $alert->exchange;
        }

        return null;
    }

    public function getCurrentPriceProperty(): ?float
    {
        if ($this->quote && isset($this->quote['price']) && is_numeric($this->quote['price'])) {
            return (float) $this->quote['price'];
        }

        if ($this->stock && $this->stock->last_price) {
            return (float) $this->stock->last_price->toDecimal();
        }

        if (! empty($this->candles)) {
            $lastCandle = end($this->candles);

            return (float) $lastCandle['close'];
        }

        return null;
    }

    public function getPriceChangeProperty(): ?float
    {
        if ($this->quote && isset($this->quote['daily_change']) && is_numeric($this->quote['daily_change'])) {
            return (float) $this->quote['daily_change'];
        }

        if ($this->stock && $this->stock->daily_change) {
            return (float) $this->stock->daily_change->toDecimal();
        }

        return null;
    }

    public function getPriceChangePercentProperty(): ?float
    {
        if ($this->quote && isset($this->quote['daily_change_percent']) && is_numeric($this->quote['daily_change_percent'])) {
            return (float) $this->quote['daily_change_percent'];
        }

        if ($this->stock && $this->stock->daily_change_percent !== null) {
            return $this->stock->daily_change_percent / 10000;
        }

        return null;
    }

    public function getVolumeProperty(): ?float
    {
        if ($this->quote && isset($this->quote['volume']) && is_numeric($this->quote['volume'])) {
            return (float) $this->quote['volume'];
        }

        if ($this->stock && $this->stock->volume !== null) {
            return (float) $this->stock->volume;
        }

        if (! empty($this->candles)) {
            $lastCandle = end($this->candles);

            return (float) $lastCandle['volume'];
        }

        return null;
    }

    public function getMarketCapProperty(): ?float
    {
        if ($this->quote && isset($this->quote['market_cap']) && is_numeric($this->quote['market_cap'])) {
            return (float) $this->quote['market_cap'];
        }

        if ($this->profile && isset($this->profile['market_cap']) && is_numeric($this->profile['market_cap'])) {
            return (float) $this->profile['market_cap'];
        }

        $alert = $this->activeSetupAlert ?? $this->latestSetupAlert;
        if ($alert && $alert->market_cap !== null) {
            return (float) $alert->market_cap;
        }

        return null;
    }

    public function getMarketDataTimestampProperty(): ?string
    {
        // Try to derive timestamp from candle data if available
        if (! empty($this->candles)) {
            $lastCandle = end($this->candles);

            return Carbon::createFromTimestamp($lastCandle['time'])->toDateString();
        }

        // Fallback to stock record last check
        if ($this->stock && $this->stock->last_checked_at) {
            return $this->stock->last_checked_at->toDateTimeString();
        }

        return null;
    }

    public function loadSetupAlerts(?StockBuySetupScorer $scorer = null): void
    {
        $alerts = StockBuySetupAlert::where('symbol', $this->symbol)
            ->orderByDesc('spike_date')
            ->orderByDesc('id')
            ->get();

        $this->setupAlertsByType = $alerts->unique('setup_type')->values();

        $overallLatest = $this->setupAlertsByType->first();

        if ($this->selectedSetupType !== null) {
            $this->activeSetupAlert = $this->setupAlertsByType
                ->first(fn ($alert) => strtolower((string) $alert->setup_type) === strtolower((string) $this->selectedSetupType));
        }

        if (! $this->activeSetupAlert) {
            $this->activeSetupAlert = $overallLatest;
            $this->selectedSetupType = $overallLatest?->setup_type;
        }

        $this->latestSetupAlert = $this->activeSetupAlert;

        $this->updateBreakdown($scorer);
    }

    public function selectSetupType(string $type, ?StockBuySetupScorer $scorer = null): void
    {
        $this->selectedSetupType = trim($type);
        $this->loadSetupAlerts($scorer);
    }

    public function toggleSetupType(string $type, ?StockBuySetupScorer $scorer = null): void
    {
        $this->selectSetupType($type, $scorer);
    }

    public function setSetupType(string $type, ?StockBuySetupScorer $scorer = null): void
    {
        $this->selectSetupType($type, $scorer);
    }

    public function updatedSelectedSetupType(string $value): void
    {
        $this->selectSetupType($value);
    }

    public function updateBreakdown(?StockBuySetupScorer $scorer = null): void
    {
        if (! $this->activeSetupAlert) {
            $this->setupScoreBreakdown = [];

            return;
        }

        $scorer = $scorer ?? app(StockBuySetupScorer::class);
        try {
            $this->setupScoreBreakdown = $scorer->breakdown($this->activeSetupAlert);
        } catch (\Throwable) {
            $this->setupScoreBreakdown = [];
        }
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.stocks.show');
    }
}
