<?php

namespace App\Services\MarketData;

use Carbon\CarbonInterface;

interface MarketDataProvider
{
    /**
     * Return normalized earnings calendar rows in the given date range.
     *
     * Each row keys (where available):
     *   symbol, company_name, exchange, report_date (Y-m-d), report_time,
     *   fiscal_period, eps_estimated, eps_actual, revenue_estimated,
     *   revenue_actual, raw (array of provider payload).
     *
     * @return array<int, array<string, mixed>>
     */
    public function earningsCalendar(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Return normalized earnings surprise rows in the given date range.
     *
     * Each row keys (where available):
     *   symbol, report_date, eps_estimated, eps_actual, eps_surprise,
     *   eps_surprise_percent, raw.
     *
     * @return array<int, array<string, mixed>>
     */
    public function earningsSurprises(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Quote for a single symbol or null on failure.
     *
     * Keys: price, volume, avg_volume, market_cap, shares_outstanding,
     * float_shares, free_float, exchange.
     *
     * NOTE: `market_cap` is a provider-reported fallback only — the canonical
     * value should be computed by callers as price × shares_outstanding
     * via {@see \App\Services\MarketData\MarketCap::compute()}.
     *
     * @return array<string, mixed>|null
     */
    public function quote(string $symbol): ?array;

    /**
     * Company profile (exchange, market cap, name, currency) or null.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $symbol): ?array;

    /**
     * Return normalized analyst-estimate rows for a single symbol.
     *
     * Each row keys (where available):
     *   symbol, period (Y-m-d quarter end), eps_avg, eps_high, eps_low,
     *   eps_num_analysts, raw.
     *
     * @return array<int, array<string, mixed>>
     */
    public function analystEstimates(string $symbol, string $period = 'quarter'): array;

    /**
     * Return normalized quarterly income statement rows for a symbol.
     *
     * Each row keys where available:
     *   date, revenue, net_income, eps, raw.
     *
     * @return array<int, array<string, mixed>>
     */
    public function quarterlyIncomeStatements(string $symbol, int $limit = 8): array;

    /**
     * Return normalized quarterly balance sheet rows for a symbol.
     *
     * Each row keys where available:
     *   date, stockholders_equity, raw.
     *
     * @return array<int, array<string, mixed>>
     */
    public function quarterlyBalanceSheets(string $symbol, int $limit = 8): array;

    /**
     * Return normalized rows from a company / equity screener filtered
     * by the given criteria (currently: marketCapMoreThan, exchange list).
     *
     * Each row keys (where available):
     *   symbol, company_name, exchange, market_cap, shares_outstanding,
     *   float_shares, free_float, price, raw.
     *
     * `market_cap` is a provider-reported fallback only — prefer the
     * computed value via {@see \App\Services\MarketData\MarketCap::compute()}.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function companyScreener(array $filters = []): array;

    /**
     * Return normalized daily OHLCV bars for a single symbol over the
     * requested inclusive date range. Sorted ascending by date.
     *
     * Each row keys (where available):
     *   date (Y-m-d), open, high, low, close, adj_close, volume.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historicalDailyBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array;
}
