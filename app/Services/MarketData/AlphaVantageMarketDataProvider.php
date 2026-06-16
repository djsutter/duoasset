<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use App\Types\FiatMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://www.alphavantage.co/query',
        private readonly int $cacheTtlSeconds = 86_400, // 24h
        private readonly int $timeoutSeconds = 10,
    ) {}

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

        $payload = Cache::remember($cacheKey, $this->cacheTtlSeconds, function () use ($symbol) {
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
}
