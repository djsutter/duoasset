<?php

namespace App\Services\Stocks;

use App\Models\CompanyProfile;
use App\Models\Stock;
use App\Services\MarketData\MarketDataProvider;

class InstrumentClassifier
{
    public const TYPE_STOCK = 'stock';

    public const TYPE_FUTURES = 'futures';

    public function __construct(
        protected MarketDataProvider $provider
    ) {}

    /**
     * Normalize external commodity/futures symbol aliases (such as CLUSDT -> CLUSD)
     * against configured aliases and FMP's /commodities-list catalog.
     */
    public function normalizeFuturesSymbol(string $symbol): string
    {
        $sym = strtoupper(trim($symbol));
        if ($sym === '') {
            return '';
        }

        $aliases = (array) config('market_data.futures.aliases', []);
        if (isset($aliases[$sym])) {
            return strtoupper(trim((string) $aliases[$sym]));
        }

        $configuredFutures = (array) config('market_data.futures.instruments', []);
        if (isset($configuredFutures[$sym])) {
            return $sym;
        }

        // In other trading systems, USD-denominated futures such as CLUSD are known as CLUSDT.
        // When a *USDT symbol corresponds to an FMP commodity symbol (*USD) in config or FMP's
        // Commodities List API (/commodities-list), map it to the canonical FMP symbol.
        if (str_ends_with($sym, 'USDT') && strlen($sym) > 4) {
            $usdCandidate = substr($sym, 0, -1); // e.g. CLUSDT -> CLUSD
            if (isset($configuredFutures[$usdCandidate]) || $this->findProviderCommodity($usdCandidate) !== null) {
                return $usdCandidate;
            }
        }

        return $sym;
    }

    /**
     * Determine whether a symbol represents a futures instrument or a stock,
     * using application metadata and FMP-supported instrument discovery.
     */
    public function classify(string $symbol, ?array $quote = null, ?array $profile = null): string
    {
        $symbol = $this->normalizeFuturesSymbol($symbol);
        if ($symbol === '') {
            return self::TYPE_STOCK;
        }

        // 1. Check configured FMP commodity catalog
        $configuredFutures = (array) config('market_data.futures.instruments', []);
        if (isset($configuredFutures[$symbol])) {
            return self::TYPE_FUTURES;
        }

        // 2. Check already-fetched quote or profile exchange/type metadata
        if (is_array($quote)) {
            $quoteExchange = strtoupper(trim((string) ($quote['exchange'] ?? '')));
            if (in_array($quoteExchange, ['COMMODITY', 'CME', 'COMEX', 'NYMEX', 'CBOT', 'ICE'], true)) {
                return self::TYPE_FUTURES;
            }
        }

        if (is_array($profile)) {
            $profileExchange = strtoupper(trim((string) ($profile['exchange'] ?? '')));
            $profileType = strtolower(trim((string) ($profile['type'] ?? '')));
            if ($profileExchange === 'COMMODITY' || in_array($profileType, ['futures', 'commodity'], true)) {
                return self::TYPE_FUTURES;
            }
            if (! empty($profile['company_name']) || ! empty($profile['sector']) || ! empty($profile['market_cap'])) {
                return self::TYPE_STOCK;
            }
        }

        // 3. Check local Stock or CompanyProfile records for known equities
        try {
            $localStock = Stock::where('symbol', $symbol)->first();
            if ($localStock) {
                return self::TYPE_STOCK;
            }

            $localProfile = CompanyProfile::where('symbol', $symbol)->first();
            if ($localProfile) {
                $localType = strtolower(trim((string) ($localProfile->type ?? '')));
                $localExchange = strtoupper(trim((string) ($localProfile->exchange ?? '')));
                if ($localExchange === 'COMMODITY' || in_array($localType, ['futures', 'commodity'], true)) {
                    return self::TYPE_FUTURES;
                }

                return self::TYPE_STOCK;
            }
        } catch (\Throwable) {
            // Ignore database unavailability during early bootstrap
        }

        // 4. Check FMP /commodities-list discovery via provider
        $commodityRow = $this->findProviderCommodity($symbol);
        if ($commodityRow !== null) {
            return self::TYPE_FUTURES;
        }

        // 5. Fallback to provider quote exchange classification if quote wasn't supplied
        if ($quote === null) {
            try {
                $fetchedQuote = $this->provider->quote($symbol);
                if (is_array($fetchedQuote)) {
                    $qEx = strtoupper(trim((string) ($fetchedQuote['exchange'] ?? '')));
                    if (in_array($qEx, ['COMMODITY', 'CME', 'COMEX', 'NYMEX', 'CBOT'], true)) {
                        return self::TYPE_FUTURES;
                    }
                }
            } catch (\Throwable) {
                // Ignore provider errors
            }
        }

        return self::TYPE_STOCK;
    }

    public function isFutures(string $symbol, ?array $quote = null, ?array $profile = null): bool
    {
        return $this->classify($symbol, $quote, $profile) === self::TYPE_FUTURES;
    }

    /**
     * Resolve rich instrument metadata for stocks or futures.
     *
     * @return array{
     *     symbol: string,
     *     canonical_symbol: string,
     *     asset_type: string,
     *     name: ?string,
     *     exchange: ?string,
     *     currency: string,
     *     price_unit: string,
     *     contract_size: ?string,
     *     trade_month: ?string,
     *     is_continuous: bool,
     *     rollover_note: ?string,
     *     market_hours: string,
     *     related_symbols: array<int, array{symbol: string, name: string, price_unit: string}>,
     *     canonical_url: string
     * }
     */
    public function resolve(string $symbol, ?array $quote = null, ?array $profile = null): array
    {
        $rawUpper = strtoupper(trim($symbol));
        $normalizedFutures = $this->normalizeFuturesSymbol($rawUpper);
        $assetType = $this->classify($rawUpper, $quote, $profile);
        $upper = $assetType === self::TYPE_FUTURES ? $normalizedFutures : $rawUpper;
        $lower = strtolower($upper);

        if ($assetType === self::TYPE_FUTURES) {
            $configured = (array) config("market_data.futures.instruments.{$upper}", []);
            $providerCommodity = $this->findProviderCommodity($upper) ?? [];

            $currency = (string) ($providerCommodity['currency']
                ?? $configured['currency']
                ?? $quote['currency']
                ?? 'USD');

            $priceUnit = (string) ($configured['price_unit'] ?? "{$currency} / contract");

            $name = $configured['name']
                ?? $providerCommodity['name']
                ?? $quote['company_name']
                ?? $quote['name']
                ?? $upper;

            $exchange = $configured['exchange']
                ?? $providerCommodity['exchange']
                ?? $quote['exchange']
                ?? 'COMMODITY';

            $tradeMonth = $providerCommodity['trade_month']
                ?? $configured['trade_month']
                ?? null;

            $related = $configured['related_symbols'] ?? [
                ['symbol' => 'CLUSD', 'name' => 'WTI Crude Oil', 'price_unit' => 'USD / bbl'],
                ['symbol' => 'SIUSD', 'name' => 'Silver Futures', 'price_unit' => 'USD / troy oz'],
            ];

            // Filter out self from related symbols
            $related = array_values(array_filter(
                $related,
                fn (array $r) => strtoupper((string) ($r['symbol'] ?? '')) !== $upper
            ));

            return [
                'symbol' => $upper,
                'canonical_symbol' => $lower,
                'asset_type' => self::TYPE_FUTURES,
                'name' => $name,
                'exchange' => $exchange,
                'currency' => $currency,
                'price_unit' => $priceUnit,
                'contract_size' => $configured['contract_size'] ?? null,
                'trade_month' => $tradeMonth,
                'is_continuous' => true,
                'rollover_note' => (string) config('market_data.futures.default_rollover_note'),
                'market_hours' => (string) config('market_data.futures.default_market_hours'),
                'related_symbols' => $related,
                'canonical_url' => "/futures/{$lower}",
            ];
        }

        $localProfile = null;
        $localStock = null;
        try {
            $localProfile = CompanyProfile::where('symbol', $upper)->first();
            $localStock = Stock::where('symbol', $upper)->first();
        } catch (\Throwable) {
            // Ignore database unavailability
        }

        if ($profile === null && $localProfile === null && ($localStock === null || empty($localStock->company_name))) {
            try {
                $profile = $this->provider->profile($upper);
            } catch (\Throwable) {
                $profile = null;
            }
        }

        $currency = (string) ($profile['currency']
            ?? $quote['currency']
            ?? $localProfile?->currency
            ?? $localStock?->currency?->currency_code
            ?? 'USD');

        $name = $profile['company_name']
            ?? $profile['name']
            ?? $quote['company_name']
            ?? $quote['name']
            ?? $localProfile?->company_name
            ?? $localStock?->company_name
            ?? null;

        $exchange = $profile['exchange']
            ?? $quote['exchange']
            ?? $localProfile?->exchange
            ?? $localStock?->exchange?->value
            ?? null;

        $sector = $profile['sector']
            ?? $localProfile?->sector
            ?? null;

        $industry = $profile['industry']
            ?? $localProfile?->industry
            ?? null;

        return [
            'symbol' => $upper,
            'canonical_symbol' => $lower,
            'asset_type' => self::TYPE_STOCK,
            'name' => $name,
            'exchange' => $exchange,
            'sector' => $sector,
            'industry' => $industry,
            'currency' => $currency,
            'price_unit' => "{$currency} / share",
            'contract_size' => null,
            'trade_month' => null,
            'is_continuous' => false,
            'rollover_note' => null,
            'market_hours' => 'Regular Session: Mon–Fri 09:30 – 16:00 ET',
            'related_symbols' => [
                ['symbol' => 'GCUSD', 'name' => 'Gold Futures', 'price_unit' => 'USD / troy oz'],
                ['symbol' => 'CLUSD', 'name' => 'WTI Crude Oil', 'price_unit' => 'USD / bbl'],
            ],
            'canonical_url' => "/stock/{$lower}",
        ];
    }

    /**
     * Look up a symbol in the provider's FMP /commodities-list catalog.
     *
     * @return array<string, mixed>|null
     */
    protected function findProviderCommodity(string $symbol): ?array
    {
        if (! method_exists($this->provider, 'commoditiesList')) {
            return null;
        }

        try {
            $list = $this->provider->commoditiesList();
            if (! is_array($list)) {
                return null;
            }
            foreach ($list as $item) {
                if (is_array($item) && strtoupper(trim((string) ($item['symbol'] ?? ''))) === $symbol) {
                    return $item;
                }
            }
        } catch (\Throwable) {
            // Ignore provider errors
        }

        return null;
    }
}
