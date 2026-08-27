<?php

namespace App\Services\MarketData;

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

    public function earningsCalendar(CarbonInterface $from, CarbonInterface $to): array
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

    public function earningsSurprises(CarbonInterface $from, CarbonInterface $to): array
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

        return Cache::remember("fmp.quote.$symbol", now()->addMinutes(2), function () use ($symbol) {
            // Compose the StockQuote-compatible array from three FMP endpoints:
            //   /quote                — price, volume, avg volume, $ change
            //   /stock-price-change   — daily change percent (1D)
            //   /profile              — market cap, exchange, company name
            // Any endpoint that fails returns null and is simply skipped; we
            // still try to build a usable response from whatever did come back.
            $quote = $this->quoteRow($symbol) ?? [];
            $change = $this->priceChange($symbol) ?? [];
            $profile = $this->profileRaw($symbol) ?? [];

            if (! $quote && ! $change && ! $profile) {
                return null;
            }

            return [
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
                    ?? null,

                'company_name' => $quote['name']
                    ?? $profile['companyName']
                    ?? $profile['name']
                    ?? null,
            ];
        });
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
        $cacheKey = "fmp.income_quarter.$symbol.$limit";

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($symbol, $limit) {
            $rows = $this->get('income-statement', [
                'symbol' => $symbol,
                'period' => 'quarter',
                'limit' => $limit,
            ]) ?? [];

            return array_values(array_filter(array_map(
                fn ($row) => $this->normalizeIncomeStatementRow($row),
                is_array($rows) ? $rows : [],
            )));
        });
    }

    public function quarterlyBalanceSheets(string $symbol, int $limit = 8): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        $limit = max(4, min(40, $limit));
        $cacheKey = "fmp.balance_quarter.$symbol.$limit";

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($symbol, $limit) {
            $rows = $this->get('balance-sheet-statement', [
                'symbol' => $symbol,
                'period' => 'quarter',
                'limit' => $limit,
            ]) ?? [];

            return array_values(array_filter(array_map(
                fn ($row) => $this->normalizeBalanceSheetRow($row),
                is_array($rows) ? $rows : [],
            )));
        });
    }

    public function companyScreener(array $filters = []): array
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
                // FMP stable: /search-symbol?query=AAPL  (limited to public equities)
                $rows = $this->get('search-symbol', ['query' => $query, 'limit' => 10]) ?? [];

                $out = [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $symbol = $this->normalizeSymbol($row['symbol'] ?? '');
                    if ($symbol === '') {
                        continue;
                    }
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

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $cacheKey = "fmp.bars.$symbol.$fromStr.$toStr";

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($symbol, $fromStr, $toStr) {
            // FMP stable endpoint: /historical-price-eod/full?symbol=AAPL&from=...&to=...
            // Response shape varies: either {symbol, historical: [...]} or [...].
            $payload = $this->get('historical-price-eod/full', [
                'symbol' => $symbol,
                'from' => $fromStr,
                'to' => $toStr,
            ]);

            if (! is_array($payload)) {
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

            // FMP returns most-recent first; the scanner expects ascending order.
            usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

            return $out;
        });
    }

    public function historicalIntradayBars(string $symbol, string $interval, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($symbol === '') {
            return [];
        }

        $interval = trim($interval) !== '' ? trim($interval) : '1hour';
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $cacheKey = "fmp.intraday.$interval.$symbol.$fromStr.$toStr";

        // Intraday bars move during the session; keep the cache short so
        // hourly captures see fresh data but repeated symbols in one run hit.
        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($symbol, $interval, $fromStr, $toStr) {
            // FMP stable endpoint: /historical-chart/1hour?symbol=AAPL&from=...&to=...
            $payload = $this->get("historical-chart/$interval", [
                'symbol' => $symbol,
                'from' => $fromStr,
                'to' => $toStr,
            ]);

            if (! is_array($payload)) {
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

            // FMP returns most-recent first; callers expect ascending order.
            usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

            return $out;
        });
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

            return [
                'symbol' => $symbol,
                'company_name' => $row['companyName'] ?? $row['name'] ?? null,
                'exchange' => $this->mapExchange($row['exchangeShortName'] ?? $row['exchange'] ?? null),
                // Raw provider-reported market cap, kept as a fallback. The
                // canonical value is computed: price × shares_outstanding.
                'market_cap' => $this->toInt($row['mktCap'] ?? $row['marketCap'] ?? null),
                'shares_outstanding' => $this->toInt(
                    $row['sharesOutstanding'] ?? $row['outstandingShares'] ?? null,
                ),
                'float_shares' => $this->toInt($row['floatShares'] ?? null),
                'free_float' => $this->toFloat($row['freeFloat'] ?? null),
                'currency' => $row['currency'] ?? null,
                'price' => $this->toFloat($row['price'] ?? null),
                'sector' => $row['sector'] ?? null,
                'industry' => $row['industry'] ?? null,
                'sub_industry' => $row['subIndustry'] ?? $row['sub_industry'] ?? null,
                'country' => $row['country'] ?? null,
            ];
        });
    }

    /**
     * Issue a GET to the FMP base URL, with retry and structured error logging.
     */
    protected function get(string $path, array $query = []): ?array
    {
        if (! $this->apiKey) {
            Log::warning('fmp.missing_api_key', ['path' => $path]);

            return null;
        }

        $query['apikey'] = $this->apiKey;
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        try {
            /** @var Response $response */
            $response = $this->client()
                ->retry(3, 500, throw: false)
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

            return is_array($json) ? $json : null;
        } catch (Throwable $e) {
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
            'eps_surprise' => $this->toFloat($row['surprise'] ?? null),
            'eps_surprise_percent' => $this->toFloat($row['surprisePercentage'] ?? null),
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
        $cap = $row['marketCap'] ?? $row['mktCap'] ?? 0;
        $outstanding = 0;
        $price = $row['price'] ?? 0;
        if ($cap > 0 && $price > 0) {
            $outstanding = (int) $cap / $price;
        }

        return [
            'symbol' => $symbol,
            'company_name' => $row['companyName'] ?? $row['name'] ?? null,
            'exchange' => $this->mapExchange(
                $row['exchangeShortName'] ?? $row['exchange'] ?? null,
            ),
            // Raw provider-reported market cap, kept as a fallback. Callers
            // should prefer the computed value (price × shares_outstanding).
            'market_cap' => $this->toInt($row['marketCap'] ?? $row['mktCap'] ?? null),
            'shares_outstanding' => $this->toInt(
                $row['sharesOutstanding'] ?? $row['outstandingShares'] ?? $outstanding,
            ),
            'float_shares' => $this->toInt($row['floatShares'] ?? null),
            'free_float' => $this->toFloat($row['freeFloat'] ?? null),
            'price' => $this->toFloat($row['price'] ?? null),
            'sector' => $row['sector'] ?? null,
            'industry' => $row['industry'] ?? null,
            'sub_industry' => $row['subIndustry'] ?? $row['sub_industry'] ?? null,
            'country' => $row['country'] ?? null,
            'raw' => $row,
        ];
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
}
