<?php

namespace App\Services\MarketData;

use Carbon\CarbonInterface;

/**
 * In-memory artificial market data provider for testing.
 *
 * Allows pre-populating return values for any provider endpoint without
 * making network calls or requiring external API keys.
 */
class FakeMarketDataProvider implements MarketDataProvider
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $dailyBars = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $intradayBars = [];

    /** @var array<string, array<string, mixed>> */
    public array $quotes = [];

    /** @var array<string, array<string, mixed>> */
    public array $profiles = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $analystEstimates = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $incomeStatements = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $balanceSheets = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $cashFlowStatements = [];

    /** @var array<int, array<string, mixed>> */
    public array $screenerRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $calendarRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $surpriseRows = [];

    /** @var array<int, array{path:string,status:int,body:string}> */
    public array $errors = [];

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
        return $this->calendarRows;
    }

    public function earningsSurprises(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->surpriseRows;
    }

    public function quote(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));

        return $this->quotes[$symbol] ?? null;
    }

    public function profile(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));

        return $this->profiles[$symbol] ?? null;
    }

    public function analystEstimates(string $symbol, string $period = 'quarter'): array
    {
        $symbol = strtoupper(trim($symbol));

        return $this->analystEstimates[$symbol] ?? [];
    }

    public function quarterlyIncomeStatements(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        $rows = $this->incomeStatements[$symbol] ?? [];

        return array_slice($rows, 0, $limit);
    }

    public function quarterlyBalanceSheets(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        $rows = $this->balanceSheets[$symbol] ?? [];

        return array_slice($rows, 0, $limit);
    }

    public function quarterlyCashFlowStatements(string $symbol, int $limit = 8): array
    {
        $symbol = strtoupper(trim($symbol));
        $rows = $this->cashFlowStatements[$symbol] ?? [];

        return array_slice($rows, 0, $limit);
    }

    public function companyScreener(array $filters = []): array
    {
        return $this->screenerRows;
    }

    public function historicalDailyBars(string $symbol, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = strtoupper(trim($symbol));

        return $this->dailyBars[$symbol] ?? [];
    }

    public function historicalIntradayBars(string $symbol, string $interval, CarbonInterface $from, CarbonInterface $to): array
    {
        $symbol = strtoupper(trim($symbol));

        return $this->intradayBars[$symbol] ?? [];
    }
}
