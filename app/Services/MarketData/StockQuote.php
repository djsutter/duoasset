<?php

namespace App\Services\MarketData;

use App\Types\FiatMoney;
use Carbon\CarbonImmutable;

/**
 * Immutable DTO returned by MarketDataProviderInterface implementations.
 */
final class StockQuote
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $exchange,
        public readonly FiatMoney $lastPrice,
        public readonly ?FiatMoney $dailyChange = null,
        /**
         * Percent change scaled by 10_000 (4 decimal places).
         * e.g. 1.25% => 12_500.
         */
        public readonly ?int $dailyChangePercent = null,
        public readonly ?int $volume = null,
        public readonly ?FiatMoney $marketCap = null,
        public readonly ?CarbonImmutable $asOf = null,
    ) {}
}
