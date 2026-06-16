<?php

namespace App\Services\MarketData;

use App\Models\Stock;

/**
 * Pluggable contract for market data providers (Polygon, Alpha Vantage,
 * Twelve Data, Financial Modeling Prep, etc.).
 *
 * Implementations are responsible for their own HTTP, caching and rate
 * limiting; they should never modify Stock records directly. The
 * MarketWatchUpdateQuotes command owns persistence.
 */
interface MarketDataProviderInterface
{
    /**
     * Stable identifier for this provider (e.g. "polygon", "null").
     */
    public function name(): string;

    /**
     * Fetch a single quote.
     *
     * Returns null when the provider has no data for the given stock
     * (e.g. unsupported symbol). Throws on transport/API errors so the
     * command can decide whether to back off.
     */
    public function fetchQuote(Stock $stock): ?StockQuote;

    /**
     * Fetch many quotes in one round-trip when the provider supports it.
     * Default implementations may simply loop over fetchQuote().
     *
     * @param  iterable<Stock>  $stocks
     * @return iterable<StockQuote>
     */
    public function fetchQuotes(iterable $stocks): iterable;
}
