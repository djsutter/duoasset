<?php

namespace App\Providers;

use App\Services\MarketData\FmpMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\ServiceProvider;

class MarketDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarketDataProvider::class, function ($app) {
            $driver = config('market_data.provider', 'fmp');

            return match ($driver) {
                'fmp' => new FmpMarketDataProvider(
                    baseUrl: (string) config('market_data.fmp.base_url'),
                    apiKey: config('market_data.fmp.api_key'),
                ),
                default => throw new \RuntimeException("Unsupported market data provider [$driver]"),
            };
        });
    }
}
