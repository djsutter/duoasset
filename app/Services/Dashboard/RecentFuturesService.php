<?php

namespace App\Services\Dashboard;

use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use App\Models\User;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\InstrumentClassifier;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RecentFuturesService
{
    public const MAX_ITEMS = 3;

    public const ANONYMOUS_SESSION_KEY = 'recent_futures_anonymous';

    public const DEFAULT_SUPPORTED_SYMBOLS = [
        'GCUSD',
        'SIUSD',
        'CLUSD',
        'NGUSD',
        'HGUSD',
    ];

    public function __construct(
        private readonly MarketDataProvider $provider,
        private readonly InstrumentClassifier $classifier,
    ) {}

    /**
     * Record a visit to a supported futures instrument.
     *
     * - Maximum 3 unique instruments.
     * - Most recently visited first.
     * - Revisiting moves the instrument to the top.
     * - Authenticated users persist on their User record.
     * - Anonymous visitors persist strictly in their HTTP session.
     */
    public function recordVisit(string $symbol, ?User $user = null): void
    {
        $normalized = $this->classifier->normalizeFuturesSymbol($symbol);
        if ($normalized === '' || ! $this->classifier->isFutures($normalized)) {
            return;
        }

        $targetUser = $this->resolveStorageUser($user);

        $current = $this->getRecentSymbols($targetUser);
        if (($current[0] ?? null) === $normalized && count($current) <= self::MAX_ITEMS) {
            // Already at the top of the list; avoid redundant write
            return;
        }

        $updated = array_values(array_unique(array_merge([$normalized], $current)));
        $updated = array_slice($updated, 0, self::MAX_ITEMS);

        if ($targetUser !== null) {
            if (User::hasSchemaColumn('recent_futures')) {
                $targetUser->recent_futures = $updated;
                if ($targetUser->exists) {
                    $targetUser->save();
                }
            }
            session(['recent_futures_user_'.$targetUser->id => $updated]);

            return;
        }

        session([self::ANONYMOUS_SESSION_KEY => $updated]);
    }

    /**
     * Return up to 3 recently visited unique futures symbols (most recent first).
     *
     * @return list<string>
     */
    public function getRecentSymbols(?User $user = null): array
    {
        $targetUser = $this->resolveStorageUser($user);

        $raw = [];
        if ($targetUser !== null) {
            if ($targetUser->exists) {
                $fresh = User::find($targetUser->id);
                if ($fresh !== null) {
                    $targetUser = $fresh;
                }
            }
            $fromDb = $targetUser->recent_futures;
            $fromSession = session('recent_futures_user_'.$targetUser->id);
            $raw = is_array($fromDb) && ! empty($fromDb)
                ? $fromDb
                : (is_array($fromSession) ? $fromSession : []);
        } else {
            $fromSession = session(self::ANONYMOUS_SESSION_KEY, []);
            $raw = is_array($fromSession) ? $fromSession : [];
        }

        $clean = [];
        foreach ($raw as $item) {
            $sym = is_array($item)
                ? strtoupper(trim((string) ($item['symbol'] ?? '')))
                : strtoupper(trim((string) $item));
            $sym = $this->classifier->normalizeFuturesSymbol($sym);

            if ($sym !== '' && ! in_array($sym, $clean, true) && $this->classifier->isFutures($sym)) {
                $clean[] = $sym;
            }

            if (count($clean) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Retrieve the 3 most recently visited futures instruments enriched with
     * latest quote prices, actual observation timestamps, currency/units, and freshness status.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getRecentFuturesWithQuotes(?User $user = null, ?string $timezone = null): Collection
    {
        $symbols = $this->getRecentSymbols($user);
        if (empty($symbols)) {
            return collect();
        }

        $displayTimezone = $timezone
            ?: ($this->resolveStorageUser($user)?->timezone() ?? 'America/New_York');

        $quotesBySymbol = $this->fetchQuotesBatch($symbols);

        $items = [];
        foreach ($symbols as $symbol) {
            $quote = $quotesBySymbol[$symbol] ?? null;
            $usedStaleFallback = false;

            if (! is_array($quote) || ! isset($quote['price']) || ! is_numeric($quote['price']) || (float) $quote['price'] <= 0) {
                $fallback = $this->recoverLastKnownPrice($symbol);
                if ($fallback !== null) {
                    $quote = array_merge(is_array($quote) ? $quote : ['symbol' => $symbol], $fallback);
                    $usedStaleFallback = true;
                }
            }

            $meta = $this->classifier->resolve($symbol, null, is_array($quote) ? $quote : null);

            $price = isset($quote['price']) && is_numeric($quote['price']) && (float) $quote['price'] > 0
                ? (float) $quote['price']
                : null;

            $dailyChange = isset($quote['daily_change']) && is_numeric($quote['daily_change'])
                ? (float) $quote['daily_change']
                : null;

            $dailyChangePct = isset($quote['daily_change_percent']) && is_numeric($quote['daily_change_percent'])
                ? (float) $quote['daily_change_percent']
                : null;

            $observationTs = $this->extractObservationTimestamp(is_array($quote) ? $quote : null);
            $freshness = $this->evaluateFreshness($observationTs, $price !== null, $usedStaleFallback);

            $formattedTime = null;
            $formattedDateTime = null;
            if ($observationTs !== null && $observationTs > 0) {
                $dt = Carbon::createFromTimestampUTC($observationTs)->setTimezone($displayTimezone);
                $formattedTime = $dt->format('H:i:s');
                $formattedDateTime = $dt->format('M j, Y H:i:s T');
            }

            $items[] = [
                'symbol' => $symbol,
                'route_symbol' => strtolower($symbol),
                'url' => route('futures.show', ['symbol' => strtolower($symbol)]),
                'name' => $meta['name'] ?? ($quote['company_name'] ?? "{$symbol} Futures"),
                'exchange' => $meta['exchange'] ?? 'COMEX / NYMEX',
                'currency' => $meta['currency'] ?? 'USD',
                'price_unit' => $meta['price_unit'] ?? 'USD',
                'contract_size' => $meta['contract_size'] ?? null,
                'front_contract' => $meta['front_contract'] ?? null,
                'price' => $price,
                'formatted_price' => $price !== null ? number_format($price, 2) : null,
                'daily_change' => $dailyChange,
                'daily_change_percent' => $dailyChangePct,
                'quote_timestamp' => $observationTs,
                'quote_time_formatted' => $formattedTime,
                'quote_datetime_formatted' => $formattedDateTime,
                'freshness' => $freshness,
            ];
        }

        return collect($items);
    }

    /**
     * Retrieve futures/commodity symbols from the FMP Commodities List API (/commodities-list),
     * enriched with local instrument metadata where configured.
     *
     * @return list<array{symbol:string,route_symbol:string,url:string,name:string,exchange:string,currency:string,price_unit:string,trade_month:?string}>
     */
    public function getAvailableFmpCommodities(): array
    {
        $providerList = [];
        if (method_exists($this->provider, 'commoditiesList')) {
            try {
                $raw = $this->provider->commoditiesList();
                if (is_array($raw)) {
                    $providerList = $raw;
                }
            } catch (Throwable) {
                $providerList = [];
            }
        }

        $configured = (array) config('market_data.futures.instruments', []);
        $out = [];

        foreach ($providerList as $row) {
            if (! is_array($row)) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $cfg = $configured[$symbol] ?? [];
            $currency = (string) ($row['currency'] ?? $cfg['currency'] ?? 'USD');
            $out[$symbol] = [
                'symbol' => $symbol,
                'route_symbol' => strtolower($symbol),
                'url' => route('futures.show', ['symbol' => strtolower($symbol)]),
                'name' => (string) ($cfg['name'] ?? $row['name'] ?? "{$symbol} Futures"),
                'exchange' => (string) ($cfg['exchange'] ?? $row['exchange'] ?? 'COMMODITY'),
                'currency' => $currency,
                'price_unit' => (string) ($cfg['price_unit'] ?? "{$currency} / contract"),
                'trade_month' => isset($row['trade_month']) && $row['trade_month'] !== ''
                    ? (string) $row['trade_month']
                    : (isset($cfg['trade_month']) ? (string) $cfg['trade_month'] : null),
            ];
        }

        return array_values($out);
    }

    /**
     * Return metadata and links for the primary supported futures instruments (used in empty state / quick links),
     * enriched via the FMP Commodities List API (/commodities-list) when available.
     *
     * @return list<array{symbol:string,route_symbol:string,url:string,name:string,exchange:string,currency:string,price_unit:string}>
     */
    public function getSupportedInstrumentsCatalog(): array
    {
        $catalog = [];
        $configured = (array) config('market_data.futures.instruments', []);
        $fmpBySymbol = collect($this->getAvailableFmpCommodities())->keyBy('symbol');

        foreach (self::DEFAULT_SUPPORTED_SYMBOLS as $symbol) {
            $cfg = $configured[$symbol] ?? [];
            $fmp = $fmpBySymbol->get($symbol, []);
            $catalog[] = [
                'symbol' => $symbol,
                'route_symbol' => strtolower($symbol),
                'url' => route('futures.show', ['symbol' => strtolower($symbol)]),
                'name' => (string) ($cfg['name'] ?? $fmp['name'] ?? "{$symbol} Futures"),
                'exchange' => (string) ($cfg['exchange'] ?? $fmp['exchange'] ?? 'COMEX / NYMEX'),
                'currency' => (string) ($fmp['currency'] ?? $cfg['currency'] ?? 'USD'),
                'price_unit' => (string) ($cfg['price_unit'] ?? $fmp['price_unit'] ?? 'USD'),
            ];
        }

        return $catalog;
    }

    /**
     * Retrieve quotes for up to 3 symbols, preferring provider batching if available
     * and isolating per-symbol errors.
     *
     * @param  list<string>  $symbols
     * @return array<string, array<string, mixed>|null>
     */
    protected function fetchQuotesBatch(array $symbols): array
    {
        $unique = array_values(array_unique(array_map('strtoupper', $symbols)));
        if (empty($unique)) {
            return [];
        }

        if (method_exists($this->provider, 'quotes')) {
            try {
                /** @var array<string, array<string, mixed>|null> $batch */
                $batch = $this->provider->quotes($unique);
                if (is_array($batch)) {
                    return $batch;
                }
            } catch (Throwable) {
                // Fall back to individual isolated lookups
            }
        }

        $results = [];
        foreach ($unique as $symbol) {
            try {
                $results[$symbol] = $this->provider->quote($symbol);
            } catch (Throwable) {
                $results[$symbol] = null;
            }
        }

        return $results;
    }

    /**
     * Recover last valid price and observation timestamp from local storage when live quote fails.
     *
     * @return array<string, mixed>|null
     */
    protected function recoverLastKnownPrice(string $symbol): ?array
    {
        try {
            $payload = MarketDataPayload::query()
                ->where('symbol', $symbol)
                ->where('data_type', 'quote')
                ->orderByDesc('retrieved_at')
                ->first();

            if ($payload && is_array($payload->payload)) {
                $p = $payload->payload;
                $price = $p['price'] ?? $p['previous_close'] ?? null;
                if (is_numeric($price) && (float) $price > 0) {
                    $ts = $this->extractObservationTimestamp($p)
                        ?? $payload->retrieved_at?->timestamp;

                    return [
                        'symbol' => $symbol,
                        'price' => (float) $price,
                        'daily_change' => isset($p['daily_change']) && is_numeric($p['daily_change']) ? (float) $p['daily_change'] : null,
                        'daily_change_percent' => isset($p['daily_change_percent']) && is_numeric($p['daily_change_percent']) ? (float) $p['daily_change_percent'] : null,
                        'timestamp' => $ts,
                        'company_name' => $p['company_name'] ?? $p['name'] ?? null,
                    ];
                }
            }

            $bar = StockDailyBar::query()
                ->where('symbol', $symbol)
                ->where('close', '>', 0)
                ->orderByDesc('bar_date')
                ->first();

            if ($bar) {
                $barTs = CarbonImmutable::parse($bar->bar_date->toDateString().' 17:00:00', 'America/New_York')->utc()->timestamp;

                return [
                    'symbol' => $symbol,
                    'price' => (float) $bar->close,
                    'timestamp' => $barTs,
                ];
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Extract actual market observation timestamp (in epoch seconds) from a quote array.
     * Never substitutes current render time when observation time is absent.
     *
     * @param  array<string, mixed>|null  $quote
     */
    public function extractObservationTimestamp(?array $quote): ?int
    {
        if (! is_array($quote)) {
            return null;
        }

        foreach (['timestamp', 'observation_timestamp', 'regularMarketTime', 'as_of_timestamp'] as $key) {
            if (isset($quote[$key]) && is_numeric($quote[$key]) && (int) $quote[$key] > 0) {
                $val = (int) $quote[$key];

                return $val > 100_000_000_000 ? (int) floor($val / 1000) : $val;
            }
        }

        foreach (['as_of', 'published_at', 'date', 'trade_date'] as $strKey) {
            if (! empty($quote[$strKey]) && is_string($quote[$strKey])) {
                try {
                    return CarbonImmutable::parse($quote[$strKey], 'America/New_York')->utc()->timestamp;
                } catch (Throwable) {
                }
            }
        }

        return null;
    }

    /**
     * Evaluate quote freshness based on market observation timestamp and CME Globex hours.
     *
     * @return array{status:string,label:string,badge_color:string,age_seconds:?int}
     */
    public function evaluateFreshness(?int $observationTimestamp, bool $hasPrice, bool $usedStaleFallback = false, ?CarbonInterface $now = null): array
    {
        if (! $hasPrice) {
            return [
                'status' => 'unavailable',
                'label' => 'Unavailable',
                'badge_color' => 'red',
                'age_seconds' => null,
            ];
        }

        $refNow = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now('UTC');
        $marketOpen = $this->isFuturesMarketOpen($refNow);

        if ($usedStaleFallback) {
            $age = $observationTimestamp !== null ? max(0, $refNow->timestamp - $observationTimestamp) : null;

            return [
                'status' => 'stale',
                'label' => 'Stale',
                'badge_color' => 'orange',
                'age_seconds' => $age,
            ];
        }

        if ($observationTimestamp === null || $observationTimestamp <= 0) {
            return [
                'status' => $marketOpen ? 'stale' : 'market_closed',
                'label' => $marketOpen ? 'Stale' : 'Market Closed',
                'badge_color' => $marketOpen ? 'orange' : 'zinc',
                'age_seconds' => null,
            ];
        }

        $ageSeconds = max(0, $refNow->timestamp - $observationTimestamp);

        if (! $marketOpen && $ageSeconds > 900) {
            // If the quote is from before the market closed (or within normal settlement window), report Market Closed;
            // if it is older than 4 days, report Stale.
            if ($ageSeconds > 4 * 86400) {
                return [
                    'status' => 'stale',
                    'label' => 'Stale',
                    'badge_color' => 'orange',
                    'age_seconds' => $ageSeconds,
                ];
            }

            return [
                'status' => 'market_closed',
                'label' => 'Market Closed',
                'badge_color' => 'zinc',
                'age_seconds' => $ageSeconds,
            ];
        }

        // During open market hours (or recent observation):
        // <= 15 minutes: Live
        // 15 - 60 minutes: Delayed
        // > 60 minutes: Stale
        if ($ageSeconds <= 900) {
            return [
                'status' => 'live',
                'label' => 'Live',
                'badge_color' => 'emerald',
                'age_seconds' => $ageSeconds,
            ];
        }

        if ($ageSeconds <= 3600) {
            return [
                'status' => 'delayed',
                'label' => 'Delayed',
                'badge_color' => 'amber',
                'age_seconds' => $ageSeconds,
            ];
        }

        return [
            'status' => 'stale',
            'label' => 'Stale',
            'badge_color' => 'orange',
            'age_seconds' => $ageSeconds,
        ];
    }

    /**
     * Determine whether CME Globex futures market is currently open.
     * CME Globex trades Sunday 18:00 ET – Friday 17:00 ET with a daily 17:00–18:00 ET maintenance break.
     */
    public function isFuturesMarketOpen(?CarbonInterface $now = null): bool
    {
        $et = ($now ? CarbonImmutable::instance($now) : CarbonImmutable::now())->setTimezone('America/New_York');
        $dayOfWeek = $et->dayOfWeek; // 0 = Sunday, 6 = Saturday
        $hour = $et->hour;

        if ($dayOfWeek === CarbonInterface::SATURDAY) {
            return false;
        }

        if ($dayOfWeek === CarbonInterface::SUNDAY) {
            return $hour >= 18;
        }

        if ($dayOfWeek === CarbonInterface::FRIDAY && $hour >= 17) {
            return false;
        }

        // Daily CME Globex maintenance break: 17:00 - 17:59 ET
        if ($hour === 17) {
            return false;
        }

        return true;
    }

    protected function resolveStorageUser(?User $user = null): ?User
    {
        $candidate = $user ?? (Auth::check() ? Auth::user() : null);
        if (! $candidate instanceof User || $candidate->isAnonymousUser()) {
            return null;
        }

        return $candidate;
    }
}
