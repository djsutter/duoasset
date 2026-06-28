<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use App\Types\FiatMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Alpha Vantage EOD quote provider.
 *
 * Uses the `GLOBAL_QUOTE` function which returns the latest end-of-day
 * snapshot for a symbol. Because the free tier is limited to 25 requests
 * per day, each symbol's response is cached for 24 hours via the Cache
 * facade. The cache key is namespaced per provider + symbol + exchange so
 * forcing a refresh (or switching providers) is straightforward.
 *
 * Network/transport errors throw, allowing the caller (the update-quotes
 * command) to back off. "Symbol not found" responses return null.
 */
final class AlphaVantageMarketDataProvider implements MarketDataProviderInterface
{
    /**
     * Monotonic microtime of the last outbound HTTP request. Shared across
     * instances so that even if the container resolves a fresh provider
     * mid-loop, we still respect Alpha Vantage's 1-req/sec ceiling.
     */
    private static float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://www.alphavantage.co/query',
        private readonly int $cacheTtlSeconds = 86_400, // 24h
        private readonly int $timeoutSeconds = 10,
        /**
         * Minimum gap between successive HTTP requests, in milliseconds.
         * Defaults to 1001 ms — Alpha Vantage's published rate limit is one
         * request per second; the extra millisecond gives us safe headroom
         * against clock-skew false positives on their side.
         */
        private readonly int $throttleMs = 1/*1001 // Since we have paid FMP, let's not throttle so much, bounce over to FMP.*/,
        /**
         * Optional FMP fallback. When Alpha Vantage returns a throttle
         * notice (Note/Information) or a non-2xx HTTP status for the
         * quote / symbol search / overview endpoints, the call is
         * transparently retried against FMP so the UI and watch command
         * keep working even after we exhaust the AV daily budget.
         */
        private readonly ?FmpMarketDataProvider $fmpFallback = null,
    ) {}

    /**
     * Block until at least $throttleMs have elapsed since the last HTTP
     * call made through this provider. Called immediately before every
     * outbound request (quote, search, overview). Cache hits skip this
     * path entirely, so the throttle only paces real network traffic.
     */
    private function throttle(): void
    {
        if ($this->throttleMs <= 0) {
            return;
        }

        $now = microtime(true);
        $elapsedMs = (int) (($now - self::$lastRequestAt) * 1000);
        if (self::$lastRequestAt > 0.0 && $elapsedMs < $this->throttleMs) {
            usleep(($this->throttleMs - $elapsedMs) * 1000);
        }
        self::$lastRequestAt = microtime(true);
    }

    public function name(): string
    {
        return 'alpha_vantage';
    }

    public function fetchQuote(Stock $stock): ?StockQuote
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Alpha Vantage API key is not configured.');
        }

        $symbol = strtoupper($stock->symbol);
        $cacheKey = sprintf('market-data:alpha_vantage:%s:%s', $stock->exchange?->value ?? 'NA', $symbol);

        try {
            $payload = Cache::remember($cacheKey, $this->cacheTtlSeconds, function () use ($symbol) {
                $this->throttle();
                $response = $this->http
                    ->timeout($this->timeoutSeconds)
                    ->acceptJson()
                    ->get($this->baseUrl, [
                        'function' => 'GLOBAL_QUOTE',
                        'symbol' => $symbol,
                        'apikey' => $this->apiKey,
                    ]);

                if ($response->failed()) {
                    throw new RuntimeException(
                        "Alpha Vantage HTTP {$response->status()} for {$symbol}"
                    );
                }

                $json = $response->json();

                // Rate-limit / informational responses — surface as exception so
                // the command counts it as an error and we don't poison the cache.
                if (isset($json['Note']) || isset($json['Information'])) {
                    throw new RuntimeException(
                        'Alpha Vantage throttled: '.($json['Note'] ?? $json['Information'])
                    );
                }

                return $json;
            });
        } catch (Throwable $e) {
            // Try FMP fallback (configured + reachable) before bubbling
            // the exception up to the caller. Any provider failure still
            // counts as an error in MarketWatchUpdateQuotes if FMP can't
            // serve it either.
            if ($fallback = $this->fetchQuoteFromFmp($stock)) {
                Log::info('Alpha Vantage throttled, served from FMP', [
                    'symbol' => $symbol,
                    'av_error' => $e->getMessage(),
                ]);

                return $fallback;
            }

            throw $e;
        }

        $quote = $payload['Global Quote'] ?? null;
        if (! is_array($quote) || empty($quote) || empty($quote['05. price'] ?? null)) {
            Log::info('Alpha Vantage returned no quote', ['symbol' => $symbol]);

            return null;
        }

        $currency = $stock->currency?->value ?? 'USD';

        $price = FiatMoney::fromDecimal((string) $quote['05. price'], $currency);
        $change = isset($quote['09. change'])
            ? FiatMoney::fromDecimal((string) $quote['09. change'], $currency)
            : null;

        $changePctRaw = $quote['10. change percent'] ?? null;
        $changePctBps = null;
        if (is_string($changePctRaw)) {
            $clean = trim(str_replace('%', '', $changePctRaw));
            if ($clean !== '' && is_numeric($clean)) {
                // Convert to 4-decimal scaled integer (e.g. 1.25% -> 12500).
                $changePctBps = (int) round(((float) $clean) * 10_000);
            }
        }

        $volume = isset($quote['06. volume']) && is_numeric($quote['06. volume'])
            ? (int) $quote['06. volume']
            : null;

        $asOf = null;
        if (! empty($quote['07. latest trading day'])) {
            try {
                $asOf = CarbonImmutable::parse((string) $quote['07. latest trading day']);
            } catch (\Throwable) {
                $asOf = null;
            }
        }

        return new StockQuote(
            symbol: $symbol,
            exchange: $stock->exchange?->value ?? '',
            lastPrice: $price,
            dailyChange: $change,
            dailyChangePercent: $changePctBps,
            volume: $volume,
            marketCap: null, // GLOBAL_QUOTE does not return market cap.
            asOf: $asOf,
        );
    }

    public function fetchQuotes(iterable $stocks): iterable
    {
        $out = [];
        foreach ($stocks as $stock) {
            if ($quote = $this->fetchQuote($stock)) {
                $out[] = $quote;
            }
        }

        return $out;
    }

    /**
     * Symbol autocomplete via Alpha Vantage's SYMBOL_SEARCH endpoint.
     *
     * Returns a normalized array of matches:
     *   [
     *     ['symbol' => 'AAPL', 'name' => 'Apple Inc', 'region' => 'United States',
     *      'currency' => 'USD', 'type' => 'Equity', 'match_score' => 1.0],
     *     ...
     *   ]
     *
     * Returns an empty array on any error / throttle (never throws — UI-facing).
     * Cached for 6 hours per query to stay within the 25/day free-tier limit.
     */
    public function searchSymbols(string $query): array
    {
        $query = trim($query);
        if ($query === '' || $this->apiKey === '') {
            return [];
        }

        $cacheKey = sprintf('market-data:alpha_vantage:search:%s', strtolower($query));

        try {
            $payload = Cache::remember($cacheKey, 6 * 3600, function () use ($query) {
                $this->throttle();
                $response = $this->http
                    ->timeout($this->timeoutSeconds)
                    ->acceptJson()
                    ->get($this->baseUrl, [
                        'function' => 'SYMBOL_SEARCH',
                        'keywords' => $query,
                        'apikey' => $this->apiKey,
                    ]);

                if ($response->failed()) {
                    throw new RuntimeException("Alpha Vantage HTTP {$response->status()} for search");
                }

                $json = $response->json();

                if (isset($json['Note']) || isset($json['Information'])) {
                    throw new RuntimeException('Alpha Vantage throttled');
                }

                return $json;
            });
        } catch (\Throwable $e) {
            Log::info('Alpha Vantage symbol search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            // Transparent FMP fallback on throttle / HTTP failure.
            if ($this->fmpFallback) {
                try {
                    return $this->fmpFallback->searchSymbols($query);
                } catch (\Throwable $fallbackErr) {
                    Log::info('FMP search fallback failed', [
                        'query' => $query,
                        'error' => $fallbackErr->getMessage(),
                    ]);
                }
            }

            return [];
        }

        $matches = $payload['bestMatches'] ?? [];
        if (! is_array($matches)) {
            return [];
        }

        $out = [];
        foreach ($matches as $m) {
            if (! is_array($m)) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($m['1. symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $out[] = [
                'symbol' => $symbol,
                'name' => (string) ($m['2. name'] ?? ''),
                'type' => (string) ($m['3. type'] ?? ''),
                'region' => (string) ($m['4. region'] ?? ''),
                'currency' => strtoupper(trim((string) ($m['8. currency'] ?? ''))),
                'match_score' => isset($m['9. matchScore']) ? (float) $m['9. matchScore'] : 0.0,
            ];
        }

        return $out;
    }

    /**
     * Company overview via Alpha Vantage's OVERVIEW endpoint.
     *
     * Returns:
     *   ['symbol' => 'AAPL', 'name' => 'Apple Inc',
     *    'exchange' => 'NASDAQ', 'currency' => 'USD',
     *    'country' => 'USA', 'sector' => '...', 'industry' => '...']
     *
     * Returns null when AV has no record / on error.
     * Cached for 24 hours.
     */
    public function lookupOverview(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '' || $this->apiKey === '') {
            return null;
        }

        $cacheKey = sprintf('market-data:alpha_vantage:overview:%s', $symbol);

        try {
            $payload = Cache::remember($cacheKey, $this->cacheTtlSeconds, function () use ($symbol) {
                $this->throttle();
                $response = $this->http
                    ->timeout($this->timeoutSeconds)
                    ->acceptJson()
                    ->get($this->baseUrl, [
                        'function' => 'OVERVIEW',
                        'symbol' => $symbol,
                        'apikey' => $this->apiKey,
                    ]);

                if ($response->failed()) {
                    throw new RuntimeException("Alpha Vantage HTTP {$response->status()} for overview");
                }

                $json = $response->json();

                if (isset($json['Note']) || isset($json['Information'])) {
                    throw new RuntimeException('Alpha Vantage throttled');
                }

                return $json;
            });
        } catch (\Throwable $e) {
            Log::info('Alpha Vantage overview failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            if ($this->fmpFallback) {
                try {
                    return $this->fmpFallback->lookupOverview($symbol);
                } catch (\Throwable $fallbackErr) {
                    Log::info('FMP overview fallback failed', [
                        'symbol' => $symbol,
                        'error' => $fallbackErr->getMessage(),
                    ]);
                }
            }

            return null;
        }

        if (! is_array($payload) || empty($payload['Symbol'] ?? null)) {
            return null;
        }

        $shares = $payload['SharesOutstanding'] ?? null;

        return [
            'symbol' => strtoupper((string) $payload['Symbol']),
            'name' => (string) ($payload['Name'] ?? ''),
            'exchange' => strtoupper((string) ($payload['Exchange'] ?? '')),
            'currency' => strtoupper((string) ($payload['Currency'] ?? '')),
            'country' => (string) ($payload['Country'] ?? ''),
            'sector' => (string) ($payload['Sector'] ?? ''),
            'industry' => (string) ($payload['Industry'] ?? ''),
            // Alpha Vantage OVERVIEW exposes SharesOutstanding (no float
            // breakdown). The downstream model accessor uses this to
            // compute market_cap = price × shares_outstanding.
            'shares_outstanding' => is_numeric($shares) ? (int) $shares : null,
        ];
    }

    /**
     * Best-effort StockQuote built from FMP's `/quote` payload, used when
     * Alpha Vantage refuses (throttle / HTTP error). Returns null when no
     * fallback is configured or FMP also has no data — the caller then
     * surfaces the original AV error.
     */
    private function fetchQuoteFromFmp(Stock $stock): ?StockQuote
    {
        if (! $this->fmpFallback) {
            return null;
        }

        try {
            $row = $this->fmpFallback->quote($stock->symbol);
        } catch (Throwable $e) {
            Log::info('FMP quote fallback failed', [
                'symbol' => $stock->symbol,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($row) || empty($row['price'])) {
            return null;
        }

        $currency = $stock->currency?->value ?? 'USD';
        $price = FiatMoney::fromDecimal((string) $row['price'], $currency);

        // FMP's composite quote() now provides daily_change (dollars),
        // daily_change_percent (percent), and market_cap when available.
        $dailyChange = isset($row['daily_change']) && $row['daily_change'] !== null
            ? FiatMoney::fromDecimal((string) $row['daily_change'], $currency)
            : null;

        $dailyChangePercent = isset($row['daily_change_percent']) && $row['daily_change_percent'] !== null
            ? (int) round(((float) $row['daily_change_percent']) * 10_000)
            : null;

        // Provider-reported market cap is kept as a fallback only — the
        // canonical value comes from price × sharesOutstanding via the
        // Stock model accessor / MarketCap::compute().
        $marketCap = isset($row['market_cap']) && $row['market_cap'] !== null
            ? FiatMoney::fromDecimal((string) $row['market_cap'], $currency)
            : null;

        $shares = isset($row['shares_outstanding']) && is_numeric($row['shares_outstanding'])
            ? (int) $row['shares_outstanding']
            : null;
        $floatShares = isset($row['float_shares']) && is_numeric($row['float_shares'])
            ? (int) $row['float_shares']
            : null;
        $freeFloat = isset($row['free_float']) && is_numeric($row['free_float'])
            ? (float) $row['free_float']
            : null;

        return new StockQuote(
            symbol: strtoupper($stock->symbol),
            exchange: $stock->exchange?->value ?? (string) ($row['exchange'] ?? ''),
            lastPrice: $price,
            dailyChange: $dailyChange,
            dailyChangePercent: $dailyChangePercent,
            volume: isset($row['volume']) ? (int) $row['volume'] : null,
            marketCap: $marketCap,
            sharesOutstanding: $shares,
            floatShares: $floatShares,
            freeFloat: $freeFloat,
            asOf: CarbonImmutable::now(),
        );
    }
}
