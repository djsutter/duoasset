<?php

namespace App\Services\MarketData;

use App\Services\Earnings\EarningsSurpriseScorer;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FmpMarketDataProvider implements MarketDataProvider
{
    /** @var array<int, array{path:string,status:int,body:string}> */
    protected array $errors = [];

    public function __construct(
        protected string $baseUrl,
        protected ?string $apiKey,
    ) {}

    /**
     * @return array<int, array{path:string,status:int,body:string}>
     */
    public function lastErrors(): array
    {
        return $this->errors;
    }

    public function clearErrors(): void
    {
        $this->errors = [];
    }

    public function earningsCalendar(CarbonInterface $from, CarbonInterface $to, bool $fresh = false): array
    {
        $rows = $this->get('earnings-calendar', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]) ?? [];

        return array_values(array_filter(array_map(
            fn ($row) => $this->normalizeCalendarRow($row),
            $rows,
        )));
    }

    public function earningsSurprises(CarbonInterface $from, CarbonInterface $to, bool $fresh = false): array
    {
        // FMP's stable API does not expose a date-range "earnings-surprises" endpoint;
        // the earnings-calendar endpoint already includes epsActual/epsEstimated and
        // revenueActual/revenueEstimated, so we derive surprises from it.
        $rows = $this->get('earnings-calendar', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]) ?? [];

        return array_values(array_filter(array_map(
            fn ($row) => $this->normalizeSurpriseRow($row),
            $rows,
        )));
    }

    public function quote(string $symbol): ?array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return null;
        }

        $isConfiguredFutures = $this->isConfiguredFuturesSymbol($symbol);
        if ($isConfiguredFutures) {
            $symbol = $this->resolveFmpFuturesSymbol($symbol);
        }

        $cached = Cache::get("fmp.quote.$symbol");
        if (is_array($cached)) {
            return $cached;
        }

        // Compose the StockQuote-compatible array from three FMP endpoints:
        //   /quote                — price, volume, avg volume, $ change
        //   /stock-price-change   — daily change percent (1D)
        //   /profile              — market cap, exchange, company name
        // Any endpoint that fails returns null and is simply skipped; we
        // still try to build a usable response from whatever did come back.
        $fmpSymbol = $isConfiguredFutures ? $this->resolveFmpFuturesSymbol($symbol) : $symbol;

        $quote = $this->quoteRow($fmpSymbol) ?? [];
        $isCommodityQuote = $isConfiguredFutures || strtoupper((string) ($quote['exchange'] ?? '')) === 'COMMODITY';
        $change = $isCommodityQuote ? [] : ($this->priceChange($symbol) ?? []);
        $profile = $isCommodityQuote ? [] : ($this->profileRaw($symbol) ?? []);

        if (! $quote && ! $change && ! $profile) {
            if ($isConfiguredFutures) {
                $fallbackQuote = $this->fetchYahooFuturesQuote($symbol);
                if (is_array($fallbackQuote)) {
                    Cache::put("fmp.quote.$symbol", $fallbackQuote, now()->addMinutes(2));

                    return $fallbackQuote;
                }
            }

            return null;
        }

        $result = [
            'symbol' => $symbol,
            // On plans where /quote and /stock-price-change are 402-gated,
            // /profile still returns price, volume, averageVolume, change
            // and changePercentage — use them as a transparent fallback so
            // AV throttle → FMP fallback in MarketWatchUpdateQuotes works.
            'price' => $this->toFloat($quote['price'] ?? $profile['price'] ?? null),
            'volume' => $this->toInt($quote['volume'] ?? $profile['volume'] ?? null),
            'avg_volume' => $this->toInt(
                $quote['avgVolume']
                    ?? $quote['averageVolume']
                    ?? $profile['averageVolume']
                    ?? $profile['avgVolume']
                    ?? null,
            ),

            'daily_change' => $this->toFloat($quote['change'] ?? $profile['change'] ?? null),

            'daily_change_percent' => $this->toFloat(
                $quote['changesPercentage']
                    ?? $quote['changePercentage']
                    ?? $profile['changePercentage']
                    ?? $profile['changesPercentage']
                    ?? $change['1D']
                    ?? $change['1d']
                    ?? null,
            ),

            // Shares-outstanding / float info from /profile (raw FMP field
            // names: sharesOutstanding, floatShares, freeFloat).
            'shares_outstanding' => $this->toInt(
                $profile['sharesOutstanding']
                    ?? $profile['outstandingShares']
                    ?? $quote['sharesOutstanding']
                    ?? null,
            ),
            'float_shares' => $this->toInt(
                $profile['floatShares']
                    ?? $quote['floatShares']
                    ?? null,
            ),
            'free_float' => $this->toFloat(
                $profile['freeFloat']
                    ?? $quote['freeFloat']
                    ?? null,
            ),

            // market_cap is provided here as a fallback only — the
            // canonical value is computed from price × shares_outstanding
            // by the caller (see MarketDataProvider::computeMarketCap()).
            'market_cap' => $this->toInt(
                $quote['marketCap']
                    ?? $profile['marketCap']
                    ?? $profile['mktCap']
                    ?? null,
            ),

            'exchange' => $quote['exchange']
                ?? $profile['exchangeShortName']
                ?? $profile['exchange']
                ?? ($isConfiguredFutures ? 'COMMODITY' : null),

            'company_name' => $quote['name']
                ?? $profile['companyName']
                ?? $profile['name']
                ?? ($isConfiguredFutures ? (config("market_data.futures.instruments.{$symbol}.name") ?? "{$symbol} Futures") : null),

            'open' => $this->toFloat($quote['open'] ?? null),
            'previous_close' => $this->toFloat($quote['previousClose'] ?? null),
            'day_low' => $this->toFloat($quote['dayLow'] ?? null),
            'day_high' => $this->toFloat($quote['dayHigh'] ?? null),
            'year_low' => $this->toFloat($quote['yearLow'] ?? null),
            'year_high' => $this->toFloat($quote['yearHigh'] ?? null),
            'timestamp' => $this->toInt($quote['timestamp'] ?? null),
            'provider' => 'fmp',
            'provider_symbol' => $fmpSymbol,
        ];

        Cache::put("fmp.quote.$symbol", $result, now()->addMinutes(2));

        return $result;
    }

    /**
     * Retrieve multiple quotes keyed by uppercase symbol, isolating per-symbol failures.
     *
     * @param  list<string>  $symbols
     * @return array<string, array<string, mixed>|null>
     */
    public function quotes(array $symbols): array
    {
        $out = [];
        foreach (array_values(array_unique(array_map(fn ($s) => $this->normalizeSymbol((string) $s), $symbols))) as $sym) {
            if ($sym === '') {
                continue;
            }
            try {
                $out[$sym] = $this->quote($sym);
            } catch (Throwable) {
                $out[$sym] = null;
            }
        }

        return $out;
    }

    /**
     * Raw /quote row (first element), no shape transformation.
     */
    private function quoteRow(string $symbol): ?array
    {
        $rows = $this->get('quote', ['symbol' => $symbol]);
        $row = is_array($rows) ? ($rows[0] ?? null) : null;

        return is_array($row) ? $row : null;
    }

    /**
     * Raw /stock-price-change row (e.g. {"symbol":"AAPL","1D":1.23,...}). 5-min cache.
     */
    private function priceChange(string $symbol): ?array
    {
        return Cache::remember("fmp.price_change.$symbol", now()->addMinutes(5), function () use ($symbol) {
            $rows = $this->get('stock-price-change', ['symbol' => $symbol]);
            $row = is_array($rows) ? ($rows[0] ?? $rows) : null;

            return is_array($row) ? $row : null;
        });
    }

    /**
     * Raw /profile row, 24h cache. Separate from the public profile() method
     * which returns a normalized shape — here we keep raw FMP keys so quote()
     * can pick exactly the fields it needs.
     */
    private function profileRaw(string $symbol): ?array
    {
        return Cache::remember("fmp.profile_raw.$symbol", now()->addHours(24), function () use ($symbol) {
            $rows = $this->get('profile', ['symbol' => $symbol]);
            $row = is_array($rows) ? ($rows[0] ?? null) : null;

            return is_array($row) ? $row : null;
        });
    }

    public function analystEstimates(string $symbol, string $period = 'quarter'): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        // FMP stable endpoint: /analyst-estimates?symbol=AAPL&period=quarter
        $rows = $this->get('analyst-estimates', [
            'symbol' => $symbol,
            'period' => $period,
        ]) ?? [];

        return array_values(array_filter(array_map(
            fn ($row) => $this->normalizeAnalystEstimateRow($row, $symbol),
            $rows,
        )));
    }

    public function quarterlyIncomeStatements(string $symbol, int $limit = 8): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        $limit = max(4, min(40, $limit));
        $cacheKey = "fmp.income_quarter.v2.$symbol.$limit";

        $fetcher = function () use ($symbol, $limit) {
            $rows = $this->get('income-statement', [
                'symbol' => $symbol,
                'period' => 'quarter',
                'limit' => $limit,
            ]) ?? [];

            return array_values(array_filter(array_map(
                fn ($row) => $this->normalizeIncomeStatementRow($row),
                is_array($rows) ? $rows : [],
            )));
        };

        if (! $this->isFinancialCachingEnabled()) {
            return $fetcher();
        }

        return Cache::remember($cacheKey, now()->addHours(12), $fetcher);
    }

    public function quarterlyBalanceSheets(string $symbol, int $limit = 8): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        $limit = max(4, min(40, $limit));
        $cacheKey = "fmp.balance_quarter.$symbol.$limit";

        $fetcher = function () use ($symbol, $limit) {
            $rows = $this->get('balance-sheet-statement', [
                'symbol' => $symbol,
                'period' => 'quarter',
                'limit' => $limit,
            ]) ?? [];

            return array_values(array_filter(array_map(
                fn ($row) => $this->normalizeBalanceSheetRow($row),
                is_array($rows) ? $rows : [],
            )));
        };

        if (! $this->isFinancialCachingEnabled()) {
            return $fetcher();
        }

        return Cache::remember($cacheKey, now()->addHours(12), $fetcher);
    }

    public function quarterlyCashFlowStatements(string $symbol, int $limit = 8): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        $limit = max(4, min(40, $limit));
        $cacheKey = "fmp.cash_flow_quarter.v2.$symbol.$limit";

        $fetcher = function () use ($symbol, $limit) {
            $rows = $this->get('cash-flow-statement', [
                'symbol' => $symbol,
                'period' => 'quarter',
                'limit' => $limit,
            ]) ?? [];

            return array_values(array_filter(array_map(
                fn ($row) => $this->normalizeCashFlowStatementRow($row),
                is_array($rows) ? $rows : [],
            )));
        };

        if (! $this->isFinancialCachingEnabled()) {
            return $fetcher();
        }

        return Cache::remember($cacheKey, now()->addHours(12), $fetcher);
    }

    protected function isFinancialCachingEnabled(): bool
    {
        try {
            return app(FinancialStorageConfigService::class)->isEnabled();
        } catch (Throwable) {
            return true;
        }
    }

    public function companyScreener(array $filters = [], bool $fresh = false): array
    {
        $query = [];

        if (isset($filters['marketCapMoreThan'])) {
            $query['marketCapMoreThan'] = (int) $filters['marketCapMoreThan'];
        }
        if (isset($filters['marketCapLowerThan'])) {
            $query['marketCapLowerThan'] = (int) $filters['marketCapLowerThan'];
        }
        if (! empty($filters['exchange'])) {
            // FMP accepts comma-separated exchange list.
            $query['exchange'] = is_array($filters['exchange'])
                ? implode(',', $filters['exchange'])
                : (string) $filters['exchange'];
        }
        if (isset($filters['limit'])) {
            $query['limit'] = (int) $filters['limit'];
        }
        $symbolStartsWith = null;
        if (! empty($filters['symbolStartsWith'])) {
            $symbolStartsWith = strtoupper(substr(trim((string) $filters['symbolStartsWith']), 0, 1));
            // Some FMP plans/endpoints support a first-letter/prefix filter; when
            // unsupported it is harmlessly ignored by the provider. We still
            // apply the same filter locally below as a safety net.
            $query['symbolStartsWith'] = $symbolStartsWith;
        }
        // Avoid OTC, funds, ETFs by default for the EPS revision universe.
        $query['isEtf'] = $filters['isEtf'] ?? 'false';
        $query['isFund'] = $filters['isFund'] ?? 'false';
        $query['isActivelyTrading'] = $filters['isActivelyTrading'] ?? 'true';

        $rows = $this->get('company-screener', $query) ?? [];

        $normalized = array_values(array_filter(array_map(
            fn ($row) => $this->normalizeScreenerRow($row),
            $rows,
        )));

        if ($symbolStartsWith !== null) {
            $normalized = array_values(array_filter(
                $normalized,
                fn (array $row) => str_starts_with((string) ($row['symbol'] ?? ''), $symbolStartsWith),
            ));
        }

        return $normalized;
    }

    /**
     * Symbol autocomplete via FMP's /search-symbol endpoint. Returns rows
     * in the same shape produced by AlphaVantageMarketDataProvider::searchSymbols
     * so it can be used as a drop-in fallback when Alpha Vantage is throttled.
     *
     * @return array<int, array{symbol:string,name:string,type:string,region:string,currency:string,match_score:float,exchange:?string}>
     */
    public function searchSymbols(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return Cache::remember(
            'fmp.search.'.strtolower($query),
            now()->addHours(6),
            function () use ($query) {
                $out = [];
                $seen = [];

                // Match against FMP's /commodities-list API first for futures/commodity queries
                // (including external aliases like CLUSDT -> CLUSD) since /search-symbol only covers equities.
                foreach ($this->searchCommoditiesList($query) as $commodityMatch) {
                    $sym = $commodityMatch['symbol'];
                    $seen[$sym] = true;
                    $out[] = $commodityMatch;
                }

                // FMP stable: /search-symbol?query=AAPL  (limited to public equities)
                $rows = $this->get('search-symbol', ['query' => $query, 'limit' => 10]) ?? [];

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $symbol = $this->normalizeSymbol($row['symbol'] ?? '');
                    if ($symbol === '' || isset($seen[$symbol])) {
                        continue;
                    }
                    $seen[$symbol] = true;
                    $exchange = $this->mapExchange(
                        $row['exchangeShortName'] ?? $row['exchange'] ?? $row['exchangeFullName'] ?? null,
                    );
                    $out[] = [
                        'symbol' => $symbol,
                        'name' => (string) ($row['name'] ?? $row['companyName'] ?? ''),
                        'type' => (string) ($row['type'] ?? 'Equity'),
                        'region' => (string) ($row['country'] ?? ''),
                        'currency' => strtoupper((string) ($row['currency'] ?? '')),
                        'match_score' => 1.0,
                        'exchange' => $exchange,
                    ];
                }

                return $out;
            }
        );
    }

    /**
     * Company overview compatible with AlphaVantageMarketDataProvider::lookupOverview's
     * return shape. Delegates to the existing profile() call.
     *
     * @return array{symbol:string,name:string,exchange:string,currency:string,country:string,sector:string,industry:string}|null
     */
    public function lookupOverview(string $symbol): ?array
    {
        $profile = $this->profile($symbol);
        if (! is_array($profile)) {
            return null;
        }

        return [
            'symbol' => (string) ($profile['symbol'] ?? strtoupper($symbol)),
            'name' => (string) ($profile['company_name'] ?? $profile['companyName'] ?? ''),
            'exchange' => strtoupper((string) ($profile['exchange'] ?? '')),
            'currency' => strtoupper((string) ($profile['currency'] ?? '')),
            'country' => (string) ($profile['country'] ?? ''),
            'sector' => (string) ($profile['sector'] ?? ''),
            'industry' => (string) ($profile['industry'] ?? ''),
            'sub_industry' => (string) ($profile['sub_industry'] ?? ''),
        ];
    }

    public function historicalDailyBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        $isConfiguredFutures = $this->isConfiguredFuturesSymbol($symbol);
        if ($isConfiguredFutures) {
            $symbol = $this->resolveFmpFuturesSymbol($symbol);
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $cacheKey = "fmp.bars.$symbol.$fromStr.$toStr";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $fmpSymbol = $isConfiguredFutures ? $this->resolveFmpFuturesSymbol($symbol) : $symbol;
        $startedAt = microtime(true);

        // FMP stable endpoint: /historical-price-eod/full?symbol=AAPL&from=...&to=...
        // Response shape varies: either {symbol, historical: [...]} or [...].
        $payload = $this->get('historical-price-eod/full', [
            'symbol' => $fmpSymbol,
            'from' => $fromStr,
            'to' => $toStr,
        ]);

        if (! is_array($payload)) {
            if ($isConfiguredFutures) {
                $fallbackBars = $this->fetchYahooFuturesDailyBars($symbol, $from, $to);
                if (! empty($fallbackBars)) {
                    Cache::put($cacheKey, $fallbackBars, now()->addHours(12));

                    return $fallbackBars;
                }
            }

            // Do not cache provider errors as empty bars.
            return [];
        }

        $rows = $payload['historical'] ?? $payload;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $norm = $this->normalizeBarRow($row);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }

        if (empty($out) && $isConfiguredFutures) {
            $fallbackBars = $this->fetchYahooFuturesDailyBars($symbol, $from, $to);
            if (! empty($fallbackBars)) {
                Cache::put($cacheKey, $fallbackBars, now()->addHours(12));

                return $fallbackBars;
            }
        }

        // FMP returns most-recent first; the scanner expects ascending order.
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        if ($isConfiguredFutures) {
            $this->logFuturesDiagnostics(
                symbol: $symbol,
                provider: 'fmp',
                providerSymbol: $fmpSymbol,
                interval: '1day',
                range: "{$fromStr}..{$toStr}",
                status: 200,
                candleCount: count($out),
                durationMs: round((microtime(true) - $startedAt) * 1000, 2),
                cacheStatus: 'miss',
                errorCategory: null,
            );
        }

        Cache::put($cacheKey, $out, now()->addHours(12));

        return $out;
    }

    public function historicalIntradayBars(string $symbol, string $interval, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        $isConfiguredFutures = $this->isConfiguredFuturesSymbol($symbol);
        if ($isConfiguredFutures) {
            $symbol = $this->resolveFmpFuturesSymbol($symbol);
        }

        $interval = trim($interval) !== '' ? trim($interval) : '1hour';
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $cacheKey = "fmp.intraday.$interval.$symbol.$fromStr.$toStr";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $fmpSymbol = $isConfiguredFutures ? $this->resolveFmpFuturesSymbol($symbol) : $symbol;
        $startedAt = microtime(true);

        // Intraday bars move during the session; keep the cache short so
        // hourly captures see fresh data but repeated symbols in one run hit.
        $payload = $this->get("historical-chart/$interval", [
            'symbol' => $fmpSymbol,
            'from' => $fromStr,
            'to' => $toStr,
        ]);

        if (! is_array($payload)) {
            if ($isConfiguredFutures) {
                $fallbackBars = $this->fetchYahooFuturesIntradayBars($symbol, $interval, $from, $to);
                if (! empty($fallbackBars)) {
                    Cache::put($cacheKey, $fallbackBars, now()->addMinutes(15));

                    return $fallbackBars;
                }
            }

            // Do not cache provider errors as empty bars.
            return [];
        }

        $rows = $payload['historical'] ?? $payload;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $norm = $this->normalizeIntradayRow($row);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }

        if (empty($out) && $isConfiguredFutures) {
            $fallbackBars = $this->fetchYahooFuturesIntradayBars($symbol, $interval, $from, $to);
            if (! empty($fallbackBars)) {
                Cache::put($cacheKey, $fallbackBars, now()->addMinutes(15));

                return $fallbackBars;
            }
        }

        // FMP returns most-recent first; callers expect ascending order.
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        if ($isConfiguredFutures) {
            $this->logFuturesDiagnostics(
                symbol: $symbol,
                provider: 'fmp',
                providerSymbol: $fmpSymbol,
                interval: $interval,
                range: "{$fromStr}..{$toStr}",
                status: 200,
                candleCount: count($out),
                durationMs: round((microtime(true) - $startedAt) * 1000, 2),
                cacheStatus: 'miss',
                errorCategory: null,
            );
        }

        Cache::put($cacheKey, $out, now()->addMinutes(15));

        return $out;
    }

    private function normalizeIntradayRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $date = $row['date'] ?? null;
        if (! $date) {
            return null;
        }

        return [
            'date' => substr((string) $date, 0, 19),
            'open' => $this->toFloat($row['open'] ?? null),
            'high' => $this->toFloat($row['high'] ?? null),
            'low' => $this->toFloat($row['low'] ?? null),
            'close' => $this->toFloat($row['close'] ?? null),
            'volume' => $this->toInt($row['volume'] ?? null),
        ];
    }

    private function normalizeBarRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $date = $row['date'] ?? null;
        if (! $date) {
            return null;
        }

        return [
            'date' => substr((string) $date, 0, 10),
            'open' => $this->toFloat($row['open'] ?? null),
            'high' => $this->toFloat($row['high'] ?? null),
            'low' => $this->toFloat($row['low'] ?? null),
            'close' => $this->toFloat($row['close'] ?? null),
            'adj_close' => $this->toFloat($row['adjClose'] ?? $row['adj_close'] ?? null),
            'volume' => $this->toInt($row['volume'] ?? null),
        ];
    }

    public function profile(string $symbol): ?array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return null;
        }

        return Cache::remember("fmp.profile.$symbol", now()->addHours(24), function () use ($symbol) {
            $rows = $this->get('profile', ['symbol' => $symbol]);
            $row = is_array($rows) ? ($rows[0] ?? null) : null;

            if (! is_array($row)) {
                return null;
            }

            $exchange = $this->mapExchange($row['exchangeShortName'] ?? $row['exchange'] ?? null);
            $marketCap = $this->toInt($row['mktCap'] ?? $row['marketCap'] ?? null);
            $price = $this->toFloat($row['price'] ?? null);
            $sharesOutstanding = $this->toInt(
                $row['sharesOutstanding'] ?? $row['outstandingShares'] ?? null,
            );
            if (($sharesOutstanding === null || $sharesOutstanding <= 0) && $marketCap !== null && $marketCap > 0 && $price !== null && $price > 0.0) {
                $derivedShares = (int) round($marketCap / $price);
                $sharesOutstanding = $derivedShares > 0 ? $derivedShares : null;
            } elseif ($sharesOutstanding !== null && $sharesOutstanding <= 0) {
                $sharesOutstanding = null;
            }

            $rawCurrency = isset($row['currency']) && is_string($row['currency']) ? strtoupper(trim($row['currency'])) : '';
            $currency = $rawCurrency !== '' ? $rawCurrency : $this->inferCurrencyFromExchangeAndSymbol($exchange, $symbol);

            return [
                'symbol' => $symbol,
                'company_name' => $row['companyName'] ?? $row['name'] ?? null,
                'exchange' => $exchange,
                // Raw provider-reported market cap, kept as a fallback. The
                // canonical value is computed: price × shares_outstanding.
                'market_cap' => $marketCap,
                'shares_outstanding' => $sharesOutstanding,
                'float_shares' => $this->toInt($row['floatShares'] ?? null),
                'free_float' => $this->toFloat($row['freeFloat'] ?? null),
                'currency' => $currency,
                'price' => $price,
                'sector' => $row['sector'] ?? null,
                'industry' => $row['industry'] ?? null,
                'sub_industry' => $row['subIndustry'] ?? $row['sub_industry'] ?? null,
                'country' => $row['country'] ?? null,
                'is_actively_trading' => $this->toBool($row['isActivelyTrading'] ?? $row['is_actively_trading'] ?? null),
                'is_etf' => $this->toBool($row['isEtf'] ?? $row['is_etf'] ?? null),
                'is_fund' => $this->toBool($row['isFund'] ?? $row['is_fund'] ?? null),
                'is_adr' => $this->toBool($row['isAdr'] ?? $row['is_adr'] ?? null),
                'type' => $row['type'] ?? $row['security_type'] ?? null,
                'description' => $row['description'] ?? null,
                'raw' => $row,
            ];
        });
    }

    /**
     * Fetch the list of FMP supported commodity/futures instruments via /commodities-list.
     *
     * @return array<int, array{symbol:string,name:string,exchange:?string,trade_month:?string,currency:string}>
     */
    public function commoditiesList(): array
    {
        $cached = Cache::get('fmp.commodities_list');
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $endpoint = (string) config('market_data.futures.commodities_list_endpoint', 'commodities-list');
        $rows = $this->get($endpoint) ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sym = $this->normalizeSymbol($row['symbol'] ?? '');
            if ($sym === '') {
                continue;
            }
            $out[] = [
                'symbol' => $sym,
                'name' => (string) ($row['name'] ?? $sym),
                'exchange' => isset($row['exchange']) && $row['exchange'] !== '' ? (string) $row['exchange'] : 'COMMODITY',
                'trade_month' => isset($row['tradeMonth']) && $row['tradeMonth'] !== '' ? (string) $row['tradeMonth'] : null,
                'currency' => strtoupper((string) ($row['currency'] ?? 'USD')),
            ];
        }

        if (! empty($out)) {
            Cache::put('fmp.commodities_list', $out, now()->addHours(24));
        }

        return $out;
    }

    /**
     * Fetch stock/ETF news from FMP /news/stock or /news/stock-latest.
     *
     * @param  array<int, string>  $symbols
     * @return array<int, array<string, mixed>>
     */
    public function stockNews(array $symbols = [], ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 50): array
    {
        $cleanSymbols = array_values(array_filter(array_map(fn ($s) => $this->normalizeSymbol((string) $s), $symbols)));
        $path = empty($cleanSymbols) ? 'news/stock-latest' : 'news/stock';
        $query = ['limit' => max(1, min(250, $limit))];
        if (! empty($cleanSymbols)) {
            $query['symbols'] = implode(',', $cleanSymbols);
        }
        if ($from) {
            $query['from'] = $from->toDateString();
        }
        if ($to) {
            $query['to'] = $to->toDateString();
        }

        $cacheKey = 'fmp.news.stock.'.md5(json_encode([$path, $query]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->get($path, $query);
        if (! is_array($rows)) {
            return [];
        }

        $clean = array_values(array_filter($rows, 'is_array'));
        Cache::put($cacheKey, $clean, now()->addSeconds((int) config('market_data.news.provider_cache_ttl_seconds', 600)));

        return $clean;
    }

    /**
     * Fetch forex/commodity news from FMP /news/forex or /news/forex-latest.
     *
     * @param  array<int, string>  $symbols
     * @return array<int, array<string, mixed>>
     */
    public function forexNews(array $symbols = [], ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 50): array
    {
        $cleanSymbols = array_values(array_filter(array_map(fn ($s) => $this->normalizeSymbol((string) $s), $symbols)));
        $path = empty($cleanSymbols) ? 'news/forex-latest' : 'news/forex';
        $query = ['limit' => max(1, min(250, $limit))];
        if (! empty($cleanSymbols)) {
            $query['symbols'] = implode(',', $cleanSymbols);
        }
        if ($from) {
            $query['from'] = $from->toDateString();
        }
        if ($to) {
            $query['to'] = $to->toDateString();
        }

        $cacheKey = 'fmp.news.forex.'.md5(json_encode([$path, $query]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->get($path, $query);
        if (! is_array($rows)) {
            return [];
        }

        $clean = array_values(array_filter($rows, 'is_array'));
        Cache::put($cacheKey, $clean, now()->addSeconds((int) config('market_data.news.provider_cache_ttl_seconds', 600)));

        return $clean;
    }

    /**
     * Fetch general financial and geopolitical news from FMP /news/general-latest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generalNews(?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 50): array
    {
        $query = ['limit' => max(1, min(250, $limit))];
        if ($from) {
            $query['from'] = $from->toDateString();
        }
        if ($to) {
            $query['to'] = $to->toDateString();
        }

        $cacheKey = 'fmp.news.general.'.md5(json_encode($query));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->get('news/general-latest', $query);
        if (! is_array($rows)) {
            return [];
        }

        $clean = array_values(array_filter($rows, 'is_array'));
        Cache::put($cacheKey, $clean, now()->addSeconds((int) config('market_data.news.provider_cache_ttl_seconds', 600)));

        return $clean;
    }

    /**
     * Fetch company press releases from FMP /news/press-releases.
     *
     * @param  array<int, string>  $symbols
     * @return array<int, array<string, mixed>>
     */
    public function pressReleases(array $symbols = [], ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 20): array
    {
        $cleanSymbols = array_values(array_filter(array_map(fn ($s) => $this->normalizeSymbol((string) $s), $symbols)));
        $path = empty($cleanSymbols) ? 'news/press-releases-latest' : 'news/press-releases';
        $query = ['limit' => max(1, min(100, $limit))];
        if (! empty($cleanSymbols)) {
            $query['symbols'] = implode(',', $cleanSymbols);
        }
        if ($from) {
            $query['from'] = $from->toDateString();
        }
        if ($to) {
            $query['to'] = $to->toDateString();
        }

        $cacheKey = 'fmp.news.press.'.md5(json_encode([$path, $query]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->get($path, $query);
        if (! is_array($rows)) {
            return [];
        }

        $clean = array_values(array_filter($rows, 'is_array'));
        Cache::put($cacheKey, $clean, now()->addMinutes(15));

        return $clean;
    }

    /**
     * Fetch macroeconomic calendar events from FMP /economic-calendar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function economicCalendar(CarbonInterface $from, CarbonInterface $to): array
    {
        $query = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];

        $cacheKey = 'fmp.economic_calendar.'.$query['from'].'.'.$query['to'];
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->get('economic-calendar', $query);
        if (! is_array($rows)) {
            return [];
        }

        $clean = array_values(array_filter($rows, 'is_array'));
        Cache::put($cacheKey, $clean, now()->addMinutes(30));

        return $clean;
    }

    /**
     * Issue a GET to the FMP base URL, with retry and structured error logging.
     */
    protected function get(string $path, array $query = []): ?array
    {
        if (! $this->apiKey) {
            $err = [
                'path' => $path,
                'status' => 401,
                'body' => 'Missing FMP API key',
            ];
            $this->errors[] = $err;
            Log::warning('fmp.missing_api_key', ['path' => $path]);

            return null;
        }

        // In unit/feature tests, skip real outbound internet requests when Http::fake() is not active
        // unless live smoke testing is explicitly enabled.
        if (app()->runningUnitTests() && ! $this->isHttpFaked() && ! env('RUN_LIVE_NEWS_SMOKE_TEST') && ! env('RUN_LIVE_FMP_TESTS')) {
            return null;
        }

        $rateLimit = (int) config('market_data.fmp.rate_limit_per_minute', 300);
        if ($rateLimit > 0) {
            $executed = \Illuminate\Support\Facades\RateLimiter::attempt(
                'fmp_api_client_limit',
                $rateLimit,
                fn () => true,
                60
            );

            if (! $executed) {
                $err = [
                    'path' => $path,
                    'status' => 429,
                    'body' => "Internal FMP rate limit exceeded ({$rateLimit} requests/minute). Throttling internal request.",
                ];
                $this->errors[] = $err;
                Log::warning('fmp.internal_rate_limit_exceeded', $err);

                return null;
            }
        }

        $query['apikey'] = $this->apiKey;
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        try {
            /** @var Response $response */
            $response = $this->client()
                ->retry(3, 500, function (\Throwable $exception) {
                    if ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response !== null) {
                        $status = $exception->response->status();

                        return $status === 429 || $status >= 500;
                    }

                    return true;
                }, throw: false)
                ->get($url, $query);

            if (! $response->successful()) {
                $err = [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ];
                $this->errors[] = $err;
                Log::warning('fmp.api_error', $err);

                return null;
            }

            $json = $response->json();

            // Detect FMP-specific error payloads returned with HTTP 200 (e.g. rate limit notes or entitlement messages)
            if (is_array($json) && (isset($json['Error Message']) || isset($json['error']))) {
                $err = [
                    'path' => $path,
                    'status' => 429,
                    'body' => (string) ($json['Error Message'] ?? $json['error'] ?? 'FMP error payload'),
                ];
                $this->errors[] = $err;
                Log::warning('fmp.api_error_payload', $err);

                return null;
            }

            return is_array($json) ? $json : null;
        } catch (Throwable $e) {
            $err = [
                'path' => $path,
                'status' => 0,
                'body' => $e->getMessage(),
            ];
            $this->errors[] = $err;
            Log::error('fmp.api_exception', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(15);
    }

    protected function normalizeCalendarRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $symbol = $this->normalizeSymbol($row['symbol'] ?? '');
        if ($symbol === '') {
            return null;
        }

        $date = $row['date'] ?? $row['reportDate'] ?? null;
        if (! $date) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'company_name' => $row['name'] ?? $row['companyName'] ?? null,
            'exchange' => $this->mapExchange($row['exchange'] ?? null),
            'report_date' => substr((string) $date, 0, 10),
            'report_time' => $row['time'] ?? null,
            'fiscal_period' => $row['fiscalDateEnding'] ?? null,
            'eps_estimated' => $this->toFloat($row['epsEstimated'] ?? null),
            'eps_actual' => $this->toFloat($row['epsActual'] ?? $row['eps'] ?? null),
            'revenue_estimated' => $this->toInt($row['revenueEstimated'] ?? null),
            'revenue_actual' => $this->toInt($row['revenueActual'] ?? $row['revenue'] ?? null),
            'raw' => $row,
        ];
    }

    protected function normalizeSurpriseRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $symbol = $this->normalizeSymbol($row['symbol'] ?? '');
        if ($symbol === '') {
            return null;
        }

        $date = $row['date'] ?? null;
        if (! $date) {
            return null;
        }

        $estimated = $this->toFloat($row['epsEstimated'] ?? $row['estimatedEarning'] ?? null);
        $actual = $this->toFloat($row['epsActual'] ?? $row['actualEarningResult'] ?? $row['eps'] ?? null);

        // Prefer our single, sign-safe EPS-surprise definition over the
        // provider's optional surprisePercentage, particularly when the
        // estimate is negative or rounds to zero.
        $calculated = EarningsSurpriseScorer::calculateSurprisePercent($actual, $estimated);

        return [
            'symbol' => $symbol,
            // Some FMP /earnings-surprises payloads include company name and
            // exchange too; pass them through so downstream consumers (the
            // earnings-surprises watchlist) don't need a separate /profile
            // call to populate the Company / Exchange columns.
            'company_name' => $row['name'] ?? $row['companyName'] ?? null,
            'exchange' => $this->mapExchange($row['exchange'] ?? $row['exchangeShortName'] ?? null),
            'report_date' => substr((string) $date, 0, 10),
            'eps_estimated' => $estimated,
            'eps_actual' => $actual,
            'eps_surprise' => $estimated !== null && $actual !== null ? $actual - $estimated : $this->toFloat($row['surprise'] ?? null),
            'eps_surprise_percent' => $calculated,
            'revenue_estimated' => $this->toInt($row['revenueEstimated'] ?? null),
            'revenue_actual' => $this->toInt($row['revenueActual'] ?? $row['revenue'] ?? null),
            'raw' => $row,
        ];
    }

    protected function normalizeAnalystEstimateRow(mixed $row, string $symbol): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        // FMP analyst-estimates fields (stable):
        //   date, symbol, estimatedRevenueLow/High/Avg,
        //   estimatedEbitdaLow/High/Avg, estimatedEbitLow/High/Avg,
        //   estimatedNetIncomeLow/High/Avg, estimatedSgaExpenseLow/High/Avg,
        //   estimatedEpsAvg, estimatedEpsHigh, estimatedEpsLow,
        //   numberAnalystEstimatedRevenue, numberAnalystsEstimatedEps
        $date = $row['date'] ?? null;
        if (! $date) {
            return null;
        }

        $avg = $this->toFloat($row['estimatedEpsAvg'] ?? $row['epsAvg'] ?? null);
        if ($avg === null) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'period' => substr((string) $date, 0, 10),
            'eps_avg' => $avg,
            'eps_high' => $this->toFloat($row['estimatedEpsHigh'] ?? $row['epsHigh'] ?? null),
            'eps_low' => $this->toFloat($row['estimatedEpsLow'] ?? $row['epsLow'] ?? null),
            'eps_num_analysts' => $this->toInt(
                $row['numberAnalystsEstimatedEps'] ?? $row['numberAnalystEstimatedEps'] ?? null,
            ),
            'raw' => $row,
        ];
    }

    protected function normalizeIncomeStatementRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $date = $row['date'] ?? $row['calendarDate'] ?? $row['fillingDate'] ?? null;
        if (! $date) {
            return null;
        }

        return [
            'date' => substr((string) $date, 0, 10),
            'revenue' => $this->toFloat($row['revenue'] ?? null),
            'net_income' => $this->toFloat($row['netIncome'] ?? $row['net_income'] ?? null),
            'eps' => $this->toFloat(
                $row['epsdiluted']
                    ?? $row['epsDiluted']
                    ?? $row['eps']
                    ?? null,
            ),
            // Used for Operating Margin Expansion (operatingIncome / revenue).
            // May legitimately be negative; only null represents missing data.
            'operating_income' => $this->toFloat($row['operatingIncome'] ?? $row['operating_income'] ?? null),
            'fiscal_year' => isset($row['fiscalYear']) && is_numeric($row['fiscalYear']) ? (int) $row['fiscalYear'] : null,
            'period' => $row['period'] ?? null,
            'filing_date' => $row['fillingDate'] ?? $row['filingDate'] ?? null,
            'reported_currency' => $row['reportedCurrency'] ?? null,
            'raw' => $row,
        ];
    }

    protected function normalizeBalanceSheetRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $date = $row['date'] ?? $row['calendarDate'] ?? $row['fillingDate'] ?? null;
        if (! $date) {
            return null;
        }

        return [
            'date' => substr((string) $date, 0, 10),
            'stockholders_equity' => $this->toFloat(
                $row['totalStockholdersEquity']
                    ?? $row['totalShareholdersEquity']
                    ?? $row['stockholdersEquity']
                    ?? $row['totalEquity']
                    ?? null,
            ),
            'book_value_per_share' => $this->toFloat(
                $row['bookValuePerShare']
                    ?? $row['book_value_per_share']
                    ?? null,
            ),
            'shares_outstanding' => $this->toInt(
                $row['commonStockSharesOutstanding']
                    ?? $row['weightedAverageShsOut']
                    ?? $row['sharesOutstanding']
                    ?? null,
            ),
            'fiscal_year' => isset($row['fiscalYear']) && is_numeric($row['fiscalYear']) ? (int) $row['fiscalYear'] : null,
            'period' => $row['period'] ?? null,
            'filing_date' => $row['fillingDate'] ?? $row['filingDate'] ?? null,
            'reported_currency' => $row['reportedCurrency'] ?? null,
            'raw' => $row,
        ];
    }

    protected function normalizeCashFlowStatementRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $date = $row['date'] ?? $row['calendarDate'] ?? $row['fillingDate'] ?? null;
        if (! $date) {
            return null;
        }

        // Prefer FMP's own freeCashFlow figure when present; otherwise derive
        // it as operatingCashFlow + capitalExpenditure (FMP reports capex as
        // a negative outflow already).
        $freeCashFlow = $this->toFloat($row['freeCashFlow'] ?? null);
        if ($freeCashFlow === null) {
            $operatingCashFlow = $this->toFloat(
                $row['operatingCashFlow'] ?? $row['netCashProvidedByOperatingActivities'] ?? null,
            );
            $capitalExpenditure = $this->toFloat($row['capitalExpenditure'] ?? null);
            if ($operatingCashFlow !== null && $capitalExpenditure !== null) {
                $freeCashFlow = $operatingCashFlow + $capitalExpenditure;
            }
        }

        return [
            'date' => substr((string) $date, 0, 10),
            // Used for FCF Margin Expansion (free_cash_flow / revenue). May
            // legitimately be negative; only null represents missing data.
            'free_cash_flow' => $freeCashFlow,
            'fiscal_year' => isset($row['fiscalYear']) && is_numeric($row['fiscalYear']) ? (int) $row['fiscalYear'] : null,
            'period' => $row['period'] ?? null,
            'filing_date' => $row['fillingDate'] ?? $row['filingDate'] ?? null,
            'reported_currency' => $row['reportedCurrency'] ?? null,
            'raw' => $row,
        ];
    }

    protected function normalizeScreenerRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $symbol = $this->normalizeSymbol($row['symbol'] ?? '');
        if ($symbol === '') {
            return null;
        }
        $cap = $this->toInt($row['marketCap'] ?? $row['mktCap'] ?? null);
        $price = $this->toFloat($row['price'] ?? null);
        $outstanding = $this->toInt($row['sharesOutstanding'] ?? $row['outstandingShares'] ?? null);
        if (($outstanding === null || $outstanding <= 0) && $cap !== null && $cap > 0 && $price !== null && $price > 0.0) {
            $derived = (int) round($cap / $price);
            $outstanding = $derived > 0 ? $derived : null;
        } elseif ($outstanding !== null && $outstanding <= 0) {
            $outstanding = null;
        }

        $exchange = $this->mapExchange(
            $row['exchangeShortName'] ?? $row['exchange'] ?? null,
        );
        $rawCurrency = isset($row['currency']) && is_string($row['currency']) ? strtoupper(trim($row['currency'])) : '';
        $currency = $rawCurrency !== '' ? $rawCurrency : $this->inferCurrencyFromExchangeAndSymbol($exchange, $symbol);

        return [
            'symbol' => $symbol,
            'company_name' => $row['companyName'] ?? $row['name'] ?? null,
            'exchange' => $exchange,
            'currency' => $currency,
            // Raw provider-reported market cap, kept as a fallback. Callers
            // should prefer the computed value (price × shares_outstanding).
            'market_cap' => $cap,
            'shares_outstanding' => $outstanding,
            'float_shares' => $this->toInt($row['floatShares'] ?? null),
            'free_float' => $this->toFloat($row['freeFloat'] ?? null),
            'price' => $price,
            'sector' => $row['sector'] ?? null,
            'industry' => $row['industry'] ?? null,
            'sub_industry' => $row['subIndustry'] ?? $row['sub_industry'] ?? null,
            'country' => $row['country'] ?? null,
            'is_actively_trading' => $this->toBool($row['isActivelyTrading'] ?? $row['is_actively_trading'] ?? null),
            'is_etf' => $this->toBool($row['isEtf'] ?? $row['is_etf'] ?? null),
            'is_fund' => $this->toBool($row['isFund'] ?? $row['is_fund'] ?? null),
            'is_adr' => $this->toBool($row['isAdr'] ?? $row['is_adr'] ?? null),
            'type' => $row['type'] ?? $row['security_type'] ?? null,
            'raw' => $row,
        ];
    }

    protected function inferCurrencyFromExchangeAndSymbol(?string $exchange, string $symbol): ?string
    {
        $ex = strtoupper(trim((string) ($exchange ?? '')));
        $sym = strtoupper(trim($symbol));

        if (
            in_array($ex, ['TSX', 'TSXV', 'TSE', 'CVE', 'NEO', 'CSE'], true)
            || str_ends_with($sym, '.TO')
            || str_ends_with($sym, '.V')
            || str_ends_with($sym, '.CN')
            || str_ends_with($sym, '.NE')
        ) {
            return 'CAD';
        }

        if (in_array($ex, ['NYSE', 'NASDAQ', 'AMEX', 'NMS', 'NYQ', 'NGM', 'NCM', 'BATS', 'ARCA'], true)) {
            return 'USD';
        }

        return null;
    }

    /**
     * Trim, uppercase, drop obvious provider garbage.
     * For TSX/TSXV symbols FMP uses suffixes like ".TO" / ".V" — preserve those.
     */
    public function normalizeSymbol(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        // Strip whitespace, leading/trailing punctuation but keep dot suffixes.
        $s = preg_replace('/\s+/', '', $s) ?? '';
        $s = trim($s, "\"'`");

        return $s;
    }

    protected function mapExchange(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $v = strtoupper(trim($value));

        return match (true) {
            in_array($v, ['NYSE', 'NEW YORK STOCK EXCHANGE'], true) => 'NYSE',
            in_array($v, ['NASDAQ', 'NASDAQ GLOBAL SELECT', 'NASDAQ GLOBAL MARKET', 'NMS'], true) => 'NASDAQ',
            in_array($v, ['TSX', 'TORONTO STOCK EXCHANGE', 'TSE'], true) => 'TSX',
            in_array($v, ['TSXV', 'CVE', 'TSX VENTURE EXCHANGE'], true) => 'TSXV',
            default => $v,
        };
    }

    protected function toFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? (float) $v : null;
    }

    protected function toInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? (int) $v : null;
    }

    protected function toBool(mixed $v): ?bool
    {
        if (! is_scalar($v) || $v === '') {
            return null;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    protected function isHttpFaked(): bool
    {
        try {
            $root = Http::getFacadeRoot();
            if (! is_object($root)) {
                return false;
            }

            return (bool) (function () {
                if (! empty($this->recording)) {
                    return true;
                }
                if ($this->stubCallbacks instanceof \Illuminate\Support\Collection) {
                    return $this->stubCallbacks->isNotEmpty();
                }

                return ! empty($this->stubCallbacks);
            })->call($root);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalize an external commodity/futures alias (e.g. CLUSDT -> CLUSD)
     * and resolve it against configured instruments and FMP's /commodities-list API.
     */
    public function normalizeFuturesAlias(string $symbol): string
    {
        $sym = $this->normalizeSymbol($symbol);
        if ($sym === '') {
            return '';
        }

        $aliases = (array) config('market_data.futures.aliases', []);
        if (isset($aliases[$sym])) {
            return $this->normalizeSymbol((string) $aliases[$sym]);
        }

        $configured = (array) config('market_data.futures.instruments', []);
        if (isset($configured[$sym])) {
            return $sym;
        }

        if (str_ends_with($sym, 'USDT') && strlen($sym) > 4) {
            $usdCandidate = substr($sym, 0, -1);
            if (isset($configured[$usdCandidate]) || $this->findCommodityInList($usdCandidate) !== null) {
                return $usdCandidate;
            }
        }

        return $sym;
    }

    /**
     * Look up a commodity symbol in FMP's /commodities-list API response.
     *
     * @return array{symbol:string,name:string,exchange:?string,trade_month:?string,currency:string}|null
     */
    public function findCommodityInList(string $symbol, bool $allowNetworkFetch = true): ?array
    {
        $sym = $this->normalizeSymbol($symbol);
        if ($sym === '') {
            return null;
        }

        $aliases = (array) config('market_data.futures.aliases', []);
        if (isset($aliases[$sym])) {
            $sym = $this->normalizeSymbol((string) $aliases[$sym]);
        } elseif (str_ends_with($sym, 'USDT') && strlen($sym) > 4) {
            $sym = substr($sym, 0, -1);
        }

        $list = $allowNetworkFetch
            ? $this->commoditiesList()
            : (is_array(Cache::get('fmp.commodities_list')) ? Cache::get('fmp.commodities_list') : []);

        foreach ($list as $item) {
            if (is_array($item) && strtoupper(trim((string) ($item['symbol'] ?? ''))) === $sym) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Search FMP's /commodities-list API for matching commodity/futures symbols or names,
     * including external *USDT aliases (e.g. CLUSDT -> CLUSD).
     *
     * @return array<int, array{symbol:string,name:string,type:string,region:string,currency:string,match_score:float,exchange:?string}>
     */
    protected function searchCommoditiesList(string $query): array
    {
        $q = strtoupper(trim($query));
        if ($q === '') {
            return [];
        }

        $normalizedAlias = $this->normalizeFuturesAlias($q);
        $list = $this->commoditiesList();
        $matches = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sym = strtoupper(trim((string) ($item['symbol'] ?? '')));
            $name = (string) ($item['name'] ?? $sym);
            if ($sym === '') {
                continue;
            }

            $exactSymbol = ($sym === $q || $sym === $normalizedAlias);
            $prefixSymbol = str_starts_with($sym, $q) || str_starts_with($sym, $normalizedAlias);
            $nameMatch = str_contains(strtoupper($name), $q);

            if (! $exactSymbol && ! $prefixSymbol && ! $nameMatch) {
                continue;
            }

            $score = $exactSymbol ? 1.0 : ($prefixSymbol ? 0.95 : 0.85);
            $matches[] = [
                'symbol' => $sym,
                'name' => $name,
                'type' => 'Futures',
                'region' => 'US',
                'currency' => strtoupper((string) ($item['currency'] ?? 'USD')),
                'match_score' => $score,
                'exchange' => (string) ($item['exchange'] ?? 'COMMODITY'),
            ];
        }

        usort($matches, fn (array $a, array $b) => $b['match_score'] <=> $a['match_score']);

        return array_slice($matches, 0, 10);
    }

    public function isConfiguredFuturesSymbol(string $symbol): bool
    {
        $sym = $this->normalizeFuturesAlias($symbol);
        $configured = (array) config('market_data.futures.instruments', []);

        if (isset($configured[$sym])) {
            return true;
        }

        $looksLikeCommodity = strlen($sym) > 4 && (str_ends_with($sym, 'USD') || str_ends_with($sym, 'USX') || str_ends_with($sym, 'USDT'));

        return $this->findCommodityInList($sym, allowNetworkFetch: $looksLikeCommodity) !== null;
    }

    public function resolveFmpFuturesSymbol(string $symbol): string
    {
        $sym = $this->normalizeFuturesAlias($symbol);

        $fromCachedList = $this->findCommodityInList($sym, allowNetworkFetch: false);
        if ($fromCachedList !== null && ! empty($fromCachedList['symbol'])) {
            return (string) $fromCachedList['symbol'];
        }

        $configured = (array) config("market_data.futures.instruments.{$sym}", []);
        if (! empty($configured)) {
            return (string) ($configured['fmp_symbol'] ?? $sym);
        }

        $looksLikeCommodity = strlen($sym) > 4 && (str_ends_with($sym, 'USD') || str_ends_with($sym, 'USX') || str_ends_with($sym, 'USDT'));
        $fromList = $this->findCommodityInList($sym, allowNetworkFetch: $looksLikeCommodity);
        if ($fromList !== null && ! empty($fromList['symbol'])) {
            return (string) $fromList['symbol'];
        }

        return $sym;
    }

    public function resolveYahooFuturesSymbol(string $symbol): ?string
    {
        $sym = $this->normalizeFuturesAlias($symbol);
        $configured = (array) config("market_data.futures.instruments.{$sym}", []);
        $yahoo = $configured['yahoo_symbol'] ?? null;

        return is_string($yahoo) && trim($yahoo) !== '' ? trim($yahoo) : null;
    }

    protected function popLastErrorForPathPrefix(string $pathPrefix): void
    {
        for ($i = count($this->errors) - 1; $i >= 0; $i--) {
            $errPath = (string) ($this->errors[$i]['path'] ?? '');
            if ($errPath === $pathPrefix || str_starts_with($errPath, $pathPrefix)) {
                array_splice($this->errors, $i, 1);

                return;
            }
        }
    }

    /**
     * Fetch a live/delayed quote for a futures contract via Yahoo Finance continuous front-month symbol
     * when FMP's subscription plan restricts that commodity symbol (e.g. CLUSD -> CL=F, NGUSD -> NG=F, HGUSD -> HG=F).
     *
     * @return array<string, mixed>|null
     */
    protected function fetchYahooFuturesQuote(string $symbol): ?array
    {
        if (! config('market_data.futures.yahoo_fallback_enabled', true)) {
            return null;
        }

        $yahooSymbol = $this->resolveYahooFuturesSymbol($symbol);
        if ($yahooSymbol === null) {
            return null;
        }

        $chart = $this->requestYahooChart($symbol, $yahooSymbol, [
            'interval' => '1d',
            'range' => '5d',
        ], 'quote', '5d');

        if ($chart === null) {
            return null;
        }

        $meta = is_array($chart['meta'] ?? null) ? $chart['meta'] : [];
        $quoteInd = is_array($chart['indicators']['quote'][0] ?? null) ? $chart['indicators']['quote'][0] : [];
        $timestamps = is_array($chart['timestamp'] ?? null) ? $chart['timestamp'] : [];

        $price = $this->toFloat($meta['regularMarketPrice'] ?? null);
        $prevClose = $this->toFloat($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? null);

        $lastIdx = ! empty($timestamps) ? count($timestamps) - 1 : -1;
        if ($price === null && $lastIdx >= 0) {
            for ($i = $lastIdx; $i >= 0; $i--) {
                $c = $this->toFloat($quoteInd['close'][$i] ?? null);
                if ($c !== null && $c > 0) {
                    $price = $c;
                    break;
                }
            }
        }

        if ($price === null || $price <= 0) {
            return null;
        }

        if ($prevClose === null && $lastIdx >= 1) {
            for ($i = $lastIdx - 1; $i >= 0; $i--) {
                $c = $this->toFloat($quoteInd['close'][$i] ?? null);
                if ($c !== null && $c > 0) {
                    $prevClose = $c;
                    break;
                }
            }
        }

        $change = ($prevClose !== null && $prevClose > 0) ? round($price - $prevClose, 4) : null;
        $changePct = ($prevClose !== null && $prevClose > 0) ? round((($price - $prevClose) / $prevClose) * 100, 4) : null;

        $volume = $this->toInt($meta['regularMarketVolume'] ?? null);
        if ($volume === null && $lastIdx >= 0) {
            $volume = $this->toInt($quoteInd['volume'][$lastIdx] ?? null);
        }

        $open = $lastIdx >= 0 ? $this->toFloat($quoteInd['open'][$lastIdx] ?? null) : null;
        $dayHigh = $this->toFloat($meta['regularMarketDayHigh'] ?? ($lastIdx >= 0 ? ($quoteInd['high'][$lastIdx] ?? null) : null));
        $dayLow = $this->toFloat($meta['regularMarketDayLow'] ?? ($lastIdx >= 0 ? ($quoteInd['low'][$lastIdx] ?? null) : null));
        $yearHigh = $this->toFloat($meta['fiftyTwoWeekHigh'] ?? null);
        $yearLow = $this->toFloat($meta['fiftyTwoWeekLow'] ?? null);
        $obsTimestamp = $this->toInt($meta['regularMarketTime'] ?? ($lastIdx >= 0 ? ($timestamps[$lastIdx] ?? null) : null));

        $this->popLastErrorForPathPrefix('quote');

        $cfgName = config("market_data.futures.instruments.{$symbol}.name");

        return [
            'symbol' => $symbol,
            'price' => $price,
            'volume' => $volume,
            'avg_volume' => null,
            'daily_change' => $change,
            'daily_change_percent' => $changePct,
            'shares_outstanding' => null,
            'float_shares' => null,
            'free_float' => null,
            'market_cap' => null,
            'exchange' => 'COMMODITY',
            'company_name' => (string) ($cfgName ?: ($meta['shortName'] ?? $meta['longName'] ?? "{$symbol} Futures")),
            'open' => $open,
            'previous_close' => $prevClose,
            'day_low' => $dayLow,
            'day_high' => $dayHigh,
            'year_low' => $yearLow,
            'year_high' => $yearHigh,
            'timestamp' => $obsTimestamp,
            'provider' => 'yahoo_finance',
            'provider_symbol' => $yahooSymbol,
        ];
    }

    /**
     * Fetch historical daily OHLCV candles for a futures contract via Yahoo Finance continuous front-month symbol.
     *
     * @return array<int, array{date:string,open:?float,high:?float,low:?float,close:?float,adj_close:?float,volume:?int}>
     */
    protected function fetchYahooFuturesDailyBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! config('market_data.futures.yahoo_fallback_enabled', true)) {
            return [];
        }

        $yahooSymbol = $this->resolveYahooFuturesSymbol($symbol);
        if ($yahooSymbol === null) {
            return [];
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $period1 = \Carbon\CarbonImmutable::parse($fromStr, 'America/New_York')->startOfDay()->utc()->timestamp;
        $period2 = \Carbon\CarbonImmutable::parse($toStr, 'America/New_York')->endOfDay()->utc()->timestamp;

        $chart = $this->requestYahooChart($symbol, $yahooSymbol, [
            'interval' => '1d',
            'period1' => $period1,
            'period2' => $period2,
            'includePrePost' => 'false',
        ], '1day', "{$fromStr}..{$toStr}");

        if ($chart === null) {
            return [];
        }

        $timestamps = is_array($chart['timestamp'] ?? null) ? $chart['timestamp'] : [];
        $quoteInd = is_array($chart['indicators']['quote'][0] ?? null) ? $chart['indicators']['quote'][0] : [];
        $adjInd = is_array($chart['indicators']['adjclose'][0] ?? null) ? $chart['indicators']['adjclose'][0] : [];

        $byDate = [];
        $count = count($timestamps);
        for ($i = 0; $i < $count; $i++) {
            $ts = $this->toInt($timestamps[$i] ?? null);
            if ($ts === null || $ts <= 0) {
                continue;
            }

            $open = $this->toFloat($quoteInd['open'][$i] ?? null);
            $high = $this->toFloat($quoteInd['high'][$i] ?? null);
            $low = $this->toFloat($quoteInd['low'][$i] ?? null);
            $close = $this->toFloat($quoteInd['close'][$i] ?? null);
            $adjClose = $this->toFloat($adjInd['adjclose'][$i] ?? $close);
            $volume = $this->toInt($quoteInd['volume'][$i] ?? 0) ?? 0;

            if ($open === null || $high === null || $low === null || $close === null) {
                continue;
            }
            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
                continue;
            }

            $dateStr = \Carbon\CarbonImmutable::createFromTimestampUTC($ts)
                ->setTimezone('America/New_York')
                ->toDateString();

            if ($dateStr < $fromStr || $dateStr > $toStr) {
                continue;
            }

            $byDate[$dateStr] = [
                'date' => $dateStr,
                'open' => round($open, 4),
                'high' => round(max($high, $open, $close), 4),
                'low' => round(min($low, $open, $close), 4),
                'close' => round($close, 4),
                'adj_close' => $adjClose !== null ? round($adjClose, 4) : round($close, 4),
                'volume' => max(0, $volume),
            ];
        }

        if (empty($byDate)) {
            return [];
        }

        ksort($byDate);
        $this->popLastErrorForPathPrefix('historical-price-eod');

        return array_values($byDate);
    }

    /**
     * Fetch historical intraday OHLCV candles for a futures contract via Yahoo Finance continuous front-month symbol.
     *
     * @return array<int, array{date:string,open:?float,high:?float,low:?float,close:?float,volume:?int}>
     */
    protected function fetchYahooFuturesIntradayBars(string $symbol, string $interval, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! config('market_data.futures.yahoo_fallback_enabled', true)) {
            return [];
        }

        $yahooSymbol = $this->resolveYahooFuturesSymbol($symbol);
        if ($yahooSymbol === null) {
            return [];
        }

        $normInterval = strtolower(trim($interval));
        $yahooInterval = match ($normInterval) {
            '1min', '1m' => '1m',
            '5min', '5m' => '5m',
            '15min', '15m' => '15m',
            '30min', '30m' => '30m',
            '1hour', '1h', '60m', '4hour', '4h' => '60m',
            default => '60m',
        };

        // Respect Yahoo Finance per-interval lookback limits so requests never fail with HTTP 400/422
        $maxDays = match ($yahooInterval) {
            '1m' => 7,
            '5m', '15m', '30m' => 58,
            default => 720,
        };

        $toUtc = \Carbon\CarbonImmutable::parse($to->toDateString(), 'America/New_York')->endOfDay()->utc();
        $nowUtc = \Carbon\CarbonImmutable::now('UTC');
        if ($toUtc->greaterThan($nowUtc)) {
            $toUtc = $nowUtc;
        }

        $fromUtc = \Carbon\CarbonImmutable::parse($from->toDateString(), 'America/New_York')->startOfDay()->utc();
        $earliestAllowed = $toUtc->subDays($maxDays);
        if ($fromUtc->lessThan($earliestAllowed)) {
            $fromUtc = $earliestAllowed;
        }

        if ($fromUtc->greaterThanOrEqualTo($toUtc)) {
            $fromUtc = $toUtc->subDays(min(5, $maxDays));
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $chart = $this->requestYahooChart($symbol, $yahooSymbol, [
            'interval' => $yahooInterval,
            'period1' => $fromUtc->timestamp,
            'period2' => $toUtc->timestamp,
            'includePrePost' => 'false',
        ], $interval, "{$fromStr}..{$toStr}");

        if ($chart === null) {
            return [];
        }

        $timestamps = is_array($chart['timestamp'] ?? null) ? $chart['timestamp'] : [];
        $quoteInd = is_array($chart['indicators']['quote'][0] ?? null) ? $chart['indicators']['quote'][0] : [];

        $bars = [];
        $count = count($timestamps);
        for ($i = 0; $i < $count; $i++) {
            $ts = $this->toInt($timestamps[$i] ?? null);
            if ($ts === null || $ts <= 0) {
                continue;
            }

            $open = $this->toFloat($quoteInd['open'][$i] ?? null);
            $high = $this->toFloat($quoteInd['high'][$i] ?? null);
            $low = $this->toFloat($quoteInd['low'][$i] ?? null);
            $close = $this->toFloat($quoteInd['close'][$i] ?? null);
            $volume = $this->toInt($quoteInd['volume'][$i] ?? 0) ?? 0;

            if ($open === null || $high === null || $low === null || $close === null) {
                continue;
            }
            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
                continue;
            }

            $dtStr = \Carbon\CarbonImmutable::createFromTimestampUTC($ts)
                ->setTimezone('America/New_York')
                ->format('Y-m-d H:i:s');

            $bars[$dtStr] = [
                'date' => $dtStr,
                'open' => round($open, 4),
                'high' => round(max($high, $open, $close), 4),
                'low' => round(min($low, $open, $close), 4),
                'close' => round($close, 4),
                'volume' => max(0, $volume),
            ];
        }

        if (empty($bars)) {
            return [];
        }

        ksort($bars);
        $sorted = array_values($bars);

        if (in_array($normInterval, ['4hour', '4h'], true)) {
            $sorted = $this->aggregateIntradayBarsTo4Hour($sorted);
        }

        $this->popLastErrorForPathPrefix('historical-chart/');

        return $sorted;
    }

    /**
     * Aggregate 1-hour intraday bars into 4-hour bars aligned to 4-hour Eastern blocks.
     *
     * @param  array<int, array{date:string,open:?float,high:?float,low:?float,close:?float,volume:?int}>  $hourlyBars
     * @return array<int, array{date:string,open:?float,high:?float,low:?float,close:?float,volume:?int}>
     */
    protected function aggregateIntradayBarsTo4Hour(array $hourlyBars): array
    {
        $buckets = [];
        foreach ($hourlyBars as $bar) {
            try {
                $dt = \Carbon\CarbonImmutable::parse($bar['date'], 'America/New_York');
                $bucketHour = (int) (floor($dt->hour / 4) * 4);
                $bucketKey = $dt->format('Y-m-d').sprintf(' %02d:00:00', $bucketHour);

                if (! isset($buckets[$bucketKey])) {
                    $buckets[$bucketKey] = [
                        'date' => $bucketKey,
                        'open' => $bar['open'],
                        'high' => $bar['high'],
                        'low' => $bar['low'],
                        'close' => $bar['close'],
                        'volume' => (int) ($bar['volume'] ?? 0),
                    ];
                } else {
                    $buckets[$bucketKey]['high'] = max((float) $buckets[$bucketKey]['high'], (float) $bar['high']);
                    $buckets[$bucketKey]['low'] = min((float) $buckets[$bucketKey]['low'], (float) $bar['low']);
                    $buckets[$bucketKey]['close'] = $bar['close'];
                    $buckets[$bucketKey]['volume'] += (int) ($bar['volume'] ?? 0);
                }
            } catch (Throwable) {
            }
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * Execute a Yahoo Finance v8 chart request with structured observability logging.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    protected function requestYahooChart(
        string $symbol,
        string $yahooSymbol,
        array $params,
        string $requestedInterval,
        string $requestedRange,
    ): ?array {
        if (app()->runningUnitTests() && ! $this->isHttpFaked() && ! env('RUN_LIVE_NEWS_SMOKE_TEST') && ! env('RUN_LIVE_FMP_TESTS')) {
            return null;
        }

        $baseUrl = rtrim((string) config('market_data.futures.yahoo_base_url', 'https://query1.finance.yahoo.com/v8/finance/chart'), '/');
        $url = $baseUrl.'/'.rawurlencode($yahooSymbol);
        $startedAt = microtime(true);

        try {
            /** @var Response $response */
            $response = $this->client()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; DuoAsset/1.0)',
                ])
                ->retry(2, 300, throw: false)
                ->get($url, $params);

            $durationMs = round((microtime(true) - $startedAt) * 1000, 2);

            if (! $response->successful()) {
                $this->logFuturesDiagnostics(
                    symbol: $symbol,
                    provider: 'yahoo_finance',
                    providerSymbol: $yahooSymbol,
                    interval: $requestedInterval,
                    range: $requestedRange,
                    status: $response->status(),
                    candleCount: 0,
                    durationMs: $durationMs,
                    cacheStatus: 'miss',
                    errorCategory: 'http_'.$response->status(),
                );

                return null;
            }

            $json = $response->json();
            $result = is_array($json['chart']['result'][0] ?? null) ? $json['chart']['result'][0] : null;
            if ($result === null) {
                return null;
            }

            $tsCount = is_array($result['timestamp'] ?? null) ? count($result['timestamp']) : 0;

            $this->logFuturesDiagnostics(
                symbol: $symbol,
                provider: 'yahoo_finance',
                providerSymbol: $yahooSymbol,
                interval: $requestedInterval,
                range: $requestedRange,
                status: $response->status(),
                candleCount: $tsCount,
                durationMs: $durationMs,
                cacheStatus: 'miss',
                errorCategory: null,
            );

            return $result;
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
            $this->logFuturesDiagnostics(
                symbol: $symbol,
                provider: 'yahoo_finance',
                providerSymbol: $yahooSymbol,
                interval: $requestedInterval,
                range: $requestedRange,
                status: 0,
                candleCount: 0,
                durationMs: $durationMs,
                cacheStatus: 'miss',
                errorCategory: 'exception',
            );

            return null;
        }
    }

    protected function logFuturesDiagnostics(
        string $symbol,
        string $provider,
        string $providerSymbol,
        string $interval,
        string $range,
        int $status,
        int $candleCount,
        float $durationMs,
        string $cacheStatus,
        ?string $errorCategory,
    ): void {
        $context = [
            'symbol' => $symbol,
            'provider' => $provider,
            'provider_symbol' => $providerSymbol,
            'requested_interval' => $interval,
            'requested_range' => $range,
            'response_status' => $status,
            'candle_count' => $candleCount,
            'request_duration' => $durationMs,
            'cache_status' => $cacheStatus,
            'error_category' => $errorCategory,
        ];

        if ($errorCategory !== null) {
            Log::warning('futures.market_data', $context);
        } else {
            Log::info('futures.market_data', $context);
        }
    }
}
