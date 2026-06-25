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
            $rows = $this->get('quote', ['symbol' => $symbol]);
            $row = is_array($rows) ? ($rows[0] ?? null) : null;

            if (! is_array($row)) {
                return null;
            }

            return [
                'symbol' => $symbol,
                'price' => $this->toFloat($row['price'] ?? null),
                'volume' => $this->toInt($row['volume'] ?? null),
                'avg_volume' => $this->toInt($row['avgVolume'] ?? $row['averageVolume'] ?? null),
                'market_cap' => $this->toInt($row['marketCap'] ?? null),
                'exchange' => $row['exchange'] ?? null,
                'company_name' => $row['name'] ?? null,
            ];
        });
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
                'market_cap' => $this->toInt($row['mktCap'] ?? $row['marketCap'] ?? null),
                'currency' => $row['currency'] ?? null,
                'price' => $this->toFloat($row['price'] ?? null),
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
            'company_name' => $row['name'] ?? null,
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
