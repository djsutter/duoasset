<?php

namespace App\Services\MarketData;

use App\Models\Stock;

/**
 * Safe default provider used when no real provider is configured.
 *
 * It never performs network I/O and always returns null. This keeps
 * `market-watch:update-quotes` runnable in development / CI / tests
 * without configuring API credentials.
 */
final class NullMarketDataProvider implements MarketDataProviderInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function fetchQuote(Stock $stock): ?StockQuote
    {
        return null;
    }

    public function fetchQuotes(iterable $stocks): iterable
    {
        return [];
    }
}
