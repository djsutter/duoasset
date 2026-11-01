<?php

namespace App\Services\MarketData;

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two-level market data provider:
 * - Level 1: In-memory cache for ultra-fast repeat access.
 * - Level 2: Authoritative persistent database (never expires automatically).
 *
 * Missing or incremental data is fetched from the upstream provider (e.g. FMP)
 * and persisted immediately across both relational models and full JSON archives.
 */
class PersistentMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        protected MarketDataProvider $upstream
    ) {}

    public function getUpstream(): MarketDataProvider
    {
        return $this->upstream;
    }

    public function historicalDailyBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $cacheKey = "duoasset.bars.{$symbol}.{$fromStr}.{$toStr}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $fromDate = CarbonImmutable::parse($fromStr);
        $toDate = CarbonImmutable::parse($toStr);

        // 1. Query existing bars from authoritative database
        $existing = StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$fromStr, $toStr])
            ->orderBy('bar_date')
            ->get();

        $needsFetch = false;
        $fetchFrom = $fromDate;
        $fetchTo = $toDate;

        if ($existing->isEmpty()) {
            $needsFetch = true;
        } else {
            $firstStored = CarbonImmutable::parse($existing->first()->bar_date);
            $lastStored = CarbonImmutable::parse($existing->last()->bar_date);

            // Check if there is an earlier gap
            if ($fromDate->lessThan($firstStored)) {
                $this->fetchAndStoreBars($symbol, $fromDate, $firstStored->subDay());
            }

            // Check if there is a later gap
            if ($toDate->greaterThan($lastStored)) {
                $gapStart = $lastStored->addDay();
                if ($gapStart->lessThanOrEqualTo($toDate)) {
                    $this->fetchAndStoreBars($symbol, $gapStart, $toDate);
                }
            }
        }

        if ($needsFetch) {
            $this->fetchAndStoreBars($symbol, $fetchFrom, $fetchTo);
        }

        // 2. Return merged ascending series from authoritative database
        $merged = StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$fromStr, $toStr])
            ->orderBy('bar_date')
            ->get()
            ->map(fn (StockDailyBar $b) => [
                'date' => $b->bar_date->toDateString(),
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->close,
                'adj_close' => $b->adj_close !== null ? (float) $b->adj_close : null,
                'volume' => (int) $b->volume,
            ])
            ->values()
            ->all();

        if (! empty($merged)) {
            Cache::put($cacheKey, $merged, now()->addHours(12));
        }

        return $merged;
    }

    protected function fetchAndStoreBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($from->greaterThan($to)) {
            return [];
        }

        try {
            $fresh = $this->upstream->historicalDailyBars($symbol, $from, $to);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.bars_fetch_failed', [
                'symbol' => $symbol,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (! empty($fresh)) {
            $now = now();
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                StockDailyBar::updateOrCreate(
                    ['symbol' => $symbol, 'bar_date' => $row['date']],
                    [
                        'open' => $row['open'] ?? null,
                        'high' => $row['high'] ?? null,
                        'low' => $row['low'] ?? null,
                        'close' => $row['close'] ?? null,
                        'adj_close' => $row['adj_close'] ?? null,
                        'volume' => $row['volume'] ?? null,
                        'retrieved_at' => $now,
                    ]
                );
            }

            // Archive raw payload
            MarketDataPayload::updateOrCreate(
                [
                    'symbol' => $symbol,
                    'data_type' => 'bars',
                    'period' => $from->toDateString() . '_' . $to->toDateString(),
                ],
                [
                    'payload' => $fresh,
                    'retrieved_at' => $now,
                ]
            );
        }

        return $fresh;
    }

    public function profile(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }

        $cacheKey = "duoasset.profile.{$symbol}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // Check authoritative database
        $stored = CompanyProfile::where('symbol', $symbol)->first();
        if ($stored) {
            $result = [
                'symbol' => $stored->symbol,
                'name' => $stored->company_name ?? '',
                'exchange' => $stored->exchange ?? '',
                'currency' => $stored->currency ?? '',
                'country' => $stored->country ?? '',
                'sector' => $stored->sector ?? '',
                'industry' => $stored->industry ?? '',
                'sub_industry' => $stored->sub_industry ?? '',
                'market_cap' => $stored->market_cap,
                'price' => $stored->price !== null ? (float) $stored->price : null,
                'shares_outstanding' => $stored->shares_outstanding,
                'float_shares' => $stored->float_shares,
                'free_float' => $stored->free_float,
            ];

            Cache::put($cacheKey, $result, now()->addHours(24));
            return $result;
        }

        // Fetch from upstream
        try {
            $fresh = $this->upstream->profile($symbol);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.profile_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (is_array($fresh) && ! empty($fresh)) {
            $now = now();
            CompanyProfile::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'company_name' => $fresh['name'] ?? null,
                    'exchange' => $fresh['exchange'] ?? null,
                    'currency' => $fresh['currency'] ?? null,
                    'country' => $fresh['country'] ?? null,
                    'sector' => $fresh['sector'] ?? null,
                    'industry' => $fresh['industry'] ?? null,
                    'sub_industry' => $fresh['sub_industry'] ?? null,
                    'market_cap' => $fresh['market_cap'] ?? null,
                    'price' => $fresh['price'] ?? null,
                    'shares_outstanding' => $fresh['shares_outstanding'] ?? null,
                    'float_shares' => $fresh['float_shares'] ?? null,
                    'free_float' => $fresh['free_float'] ?? null,
                    'retrieved_at' => $now,
                ]
            );

            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'profile', 'period' => 'default'],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );

            Cache::put($cacheKey, $fresh, now()->addHours(24));
            return $fresh;
        }

        return null;
    }

    public function quote(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }

        // Intraday quotes are dynamic; rely on upstream quote, updating stored profile prices
        try {
            $quote = $this->upstream->quote($symbol);
            if (is_array($quote) && ! empty($quote)) {
                if (isset($quote['price']) || isset($quote['market_cap'])) {
                    CompanyProfile::where('symbol', $symbol)->update(array_filter([
                        'price' => $quote['price'] ?? null,
                        'market_cap' => $quote['market_cap'] ?? null,
                        'retrieved_at' => now(),
                    ], fn ($v) => $v !== null));
                }
                return $quote;
            }
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.quote_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to stored profile if upstream fails
        $stored = CompanyProfile::where('symbol', $symbol)->first();
        if ($stored) {
            return [
                'symbol' => $stored->symbol,
                'price' => $stored->price !== null ? (float) $stored->price : null,
                'market_cap' => $stored->market_cap,
                'exchange' => $stored->exchange,
                'company_name' => $stored->company_name,
            ];
        }

        return null;
    }

    public function quarterlyIncomeStatements(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        $existing = CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_INCOME)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get();

        if ($existing->count() >= $limit) {
            return $existing->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'revenue' => (float) $f->revenue,
                'net_income' => (float) $f->net_income,
                'eps' => $f->eps !== null ? (float) $f->eps : null,
                'raw' => $f->raw_metrics ?? [],
            ])->all();
        }

        // Fetch from upstream
        try {
            $fresh = $this->upstream->quarterlyIncomeStatements($symbol, $limit);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.income_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $fresh = [];
        }

        if (! empty($fresh)) {
            $now = now();
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                CompanyFinancial::updateOrCreate(
                    [
                        'symbol' => $symbol,
                        'statement_type' => CompanyFinancial::TYPE_INCOME,
                        'fiscal_date' => $row['date'],
                        'period' => $row['period'] ?? 'quarter',
                    ],
                    [
                        'revenue' => $row['revenue'] ?? null,
                        'net_income' => $row['net_income'] ?? null,
                        'eps' => $row['eps'] ?? null,
                        'fiscal_year' => isset($row['fiscalYear']) ? (int) $row['fiscalYear'] : null,
                        'raw_metrics' => $row['raw'] ?? $row,
                        'retrieved_at' => $now,
                    ]
                );
            }

            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'income', 'period' => "limit_{$limit}"],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );

            return $fresh;
        }

        return $existing->map(fn (CompanyFinancial $f) => [
            'date' => $f->fiscal_date->toDateString(),
            'revenue' => (float) $f->revenue,
            'net_income' => (float) $f->net_income,
            'eps' => $f->eps !== null ? (float) $f->eps : null,
            'raw' => $f->raw_metrics ?? [],
        ])->all();
    }

    public function quarterlyBalanceSheets(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        $existing = CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_BALANCE_SHEET)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get();

        if ($existing->count() >= $limit) {
            return $existing->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'stockholders_equity' => (float) $f->stockholders_equity,
                'raw' => $f->raw_metrics ?? [],
            ])->all();
        }

        try {
            $fresh = $this->upstream->quarterlyBalanceSheets($symbol, $limit);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.balance_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $fresh = [];
        }

        if (! empty($fresh)) {
            $now = now();
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                CompanyFinancial::updateOrCreate(
                    [
                        'symbol' => $symbol,
                        'statement_type' => CompanyFinancial::TYPE_BALANCE_SHEET,
                        'fiscal_date' => $row['date'],
                        'period' => $row['period'] ?? 'quarter',
                    ],
                    [
                        'stockholders_equity' => $row['stockholders_equity'] ?? null,
                        'fiscal_year' => isset($row['fiscalYear']) ? (int) $row['fiscalYear'] : null,
                        'raw_metrics' => $row['raw'] ?? $row,
                        'retrieved_at' => $now,
                    ]
                );
            }

            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'balance_sheet', 'period' => "limit_{$limit}"],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );

            return $fresh;
        }

        return $existing->map(fn (CompanyFinancial $f) => [
            'date' => $f->fiscal_date->toDateString(),
            'stockholders_equity' => (float) $f->stockholders_equity,
            'raw' => $f->raw_metrics ?? [],
        ])->all();
    }

    public function quarterlyCashFlowStatements(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        $existing = CompanyFinancial::query()
            ->where('symbol', $symbol)
            ->where('statement_type', CompanyFinancial::TYPE_CASH_FLOW)
            ->orderByDesc('fiscal_date')
            ->take($limit)
            ->get();

        if ($existing->count() >= $limit) {
            return $existing->map(fn (CompanyFinancial $f) => [
                'date' => $f->fiscal_date->toDateString(),
                'free_cash_flow' => (float) $f->free_cash_flow,
                'fiscal_year' => $f->fiscal_year,
                'period' => $f->period,
                'reported_currency' => $f->reported_currency,
                'raw' => $f->raw_metrics ?? [],
            ])->all();
        }

        try {
            $fresh = $this->upstream->quarterlyCashFlowStatements($symbol, $limit);
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.cash_flow_fetch_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $fresh = [];
        }

        if (! empty($fresh)) {
            $now = now();
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                CompanyFinancial::updateOrCreate(
                    [
                        'symbol' => $symbol,
                        'statement_type' => CompanyFinancial::TYPE_CASH_FLOW,
                        'fiscal_date' => $row['date'],
                        'period' => $row['period'] ?? 'quarter',
                    ],
                    [
                        'free_cash_flow' => $row['free_cash_flow'] ?? null,
                        'fiscal_year' => isset($row['fiscal_year']) ? (int) $row['fiscal_year'] : (isset($row['fiscalYear']) ? (int) $row['fiscalYear'] : null),
                        'reported_currency' => $row['reported_currency'] ?? null,
                        'raw_metrics' => $row['raw'] ?? $row,
                        'retrieved_at' => $now,
                    ]
                );
            }

            MarketDataPayload::updateOrCreate(
                ['symbol' => $symbol, 'data_type' => 'cash_flow', 'period' => "limit_{$limit}"],
                ['payload' => $fresh, 'retrieved_at' => $now]
            );

            return $fresh;
        }

        return $existing->map(fn (CompanyFinancial $f) => [
            'date' => $f->fiscal_date->toDateString(),
            'free_cash_flow' => (float) $f->free_cash_flow,
            'fiscal_year' => $f->fiscal_year,
            'period' => $f->period,
            'reported_currency' => $f->reported_currency,
            'raw' => $f->raw_metrics ?? [],
        ])->all();
    }

    public function analystEstimates(string $symbol, string $period = 'quarter'): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return [];
        }

        $stored = MarketDataPayload::where('symbol', $symbol)
            ->where('data_type', 'analyst_estimates')
            ->where('period', $period)
            ->first();

        if ($stored && is_array($stored->payload)) {
            return $stored->payload;
        }

        try {
            $fresh = $this->upstream->analystEstimates($symbol, $period);
            if (! empty($fresh)) {
                MarketDataPayload::updateOrCreate(
                    ['symbol' => $symbol, 'data_type' => 'analyst_estimates', 'period' => $period],
                    ['payload' => $fresh, 'retrieved_at' => now()]
                );
            }
            return $fresh;
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.analyst_estimates_failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function earningsCalendar(CarbonInterface $from, CarbonInterface $to): array
    {
        $key = $from->toDateString() . '_' . $to->toDateString();
        $stored = MarketDataPayload::where('symbol', 'GLOBAL')
            ->where('data_type', 'earnings_calendar')
            ->where('period', $key)
            ->first();

        if ($stored && is_array($stored->payload)) {
            return $stored->payload;
        }

        try {
            $fresh = $this->upstream->earningsCalendar($from, $to);
            if (! empty($fresh)) {
                MarketDataPayload::updateOrCreate(
                    ['symbol' => 'GLOBAL', 'data_type' => 'earnings_calendar', 'period' => $key],
                    ['payload' => $fresh, 'retrieved_at' => now()]
                );
            }
            return $fresh;
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.earnings_calendar_failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function earningsSurprises(CarbonInterface $from, CarbonInterface $to): array
    {
        $key = $from->toDateString() . '_' . $to->toDateString();
        $stored = MarketDataPayload::where('symbol', 'GLOBAL')
            ->where('data_type', 'earnings_surprises')
            ->where('period', $key)
            ->first();

        if ($stored && is_array($stored->payload)) {
            return $stored->payload;
        }

        try {
            $fresh = $this->upstream->earningsSurprises($from, $to);
            if (! empty($fresh)) {
                MarketDataPayload::updateOrCreate(
                    ['symbol' => 'GLOBAL', 'data_type' => 'earnings_surprises', 'period' => $key],
                    ['payload' => $fresh, 'retrieved_at' => now()]
                );
            }
            return $fresh;
        } catch (Throwable $e) {
            Log::warning('persistent_market_data.earnings_surprises_failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function companyScreener(array $filters = []): array
    {
        // Screener passes through to upstream, caching profiles returned
        $results = $this->upstream->companyScreener($filters);
        if (! empty($results)) {
            $now = now();
            foreach ($results as $item) {
                if (! empty($item['symbol'])) {
                    CompanyProfile::updateOrCreate(
                        ['symbol' => strtoupper($item['symbol'])],
                        [
                            'company_name' => $item['company_name'] ?? null,
                            'exchange' => $item['exchange'] ?? null,
                            'market_cap' => $item['market_cap'] ?? null,
                            'price' => $item['price'] ?? null,
                            'shares_outstanding' => $item['shares_outstanding'] ?? null,
                            'float_shares' => $item['float_shares'] ?? null,
                            'free_float' => $item['free_float'] ?? null,
                            'retrieved_at' => $now,
                        ]
                    );
                }
            }
        }

        return $results;
    }

    public function historicalIntradayBars(string $symbol, string $interval, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->upstream->historicalIntradayBars($symbol, $interval, $from, $to);
    }

    public function lastErrors(): array
    {
        return method_exists($this->upstream, 'lastErrors')
            ? $this->upstream->lastErrors()
            : [];
    }

    public function clearErrors(): void
    {
        if (method_exists($this->upstream, 'clearErrors')) {
            $this->upstream->clearErrors();
        }
    }
}
