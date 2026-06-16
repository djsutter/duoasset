<?php

namespace App\Providers;

use App\Domain\Tax\Continuity\PoolLedgerStateBuilder;
use App\Domain\Tax\Continuity\TaxAssetStateBuilderInterface;
use App\Tax\Events\AcquisitionEventRepository;
use App\Tax\Events\EloquentAcquisitionEventRepository;
use App\Tax\SuperficialLoss\Application\ResolveSuperficialLosses;
use App\Tax\SuperficialLoss\Domain\CraSuperficialLossResolver;
use App\Tax\SuperficialLoss\Domain\SuperficialLossResolver;
use App\Tax\SuperficialLoss\Policies\CraSuperficialLossMatchingPolicy;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('money', function ($expression) {
            return "<?php echo ({$expression}) ? ({$expression})->format() : null; ?>";
        });

        // Can use @assetQuantity($asset, scale: 4)
        Blade::directive('assetQuantity', function ($expression) {
            // Split arguments: $qty, $scale
            $parts = array_map('trim', explode(',', $expression, 2));

            $qty = $parts[0];
            $scale = $parts[1] ?? 'null';

            return <<<PHP
<?php
    echo ({$qty})
        ? ({$qty})->format({$scale})
        : null;
?>
PHP;
        });

        $this->app->bind(
            \App\Contracts\Reports\CapitalGainsCraReportService::class,
            \App\Services\Reports\CraCapitalGainsReportService::class
        );
        $this->app->bind(
            \App\Contracts\Reports\CapitalGainsLedgerReportService::class,
            \App\Services\Reports\LedgerCapitalGainsReportService::class
        );

        $this->app->bind(
            AcquisitionEventRepository::class,
            EloquentAcquisitionEventRepository::class
        );

        // Bind the Matching Policy (if it has dependencies, inject them similarly)
        $this->app->singleton(CraSuperficialLossMatchingPolicy::class, function ($app) {
            return new CraSuperficialLossMatchingPolicy;
        });

        // Bind the Resolver using the policy
        $this->app->singleton(SuperficialLossResolver::class, function ($app) {
            return new CraSuperficialLossResolver(
                $app->make(CraSuperficialLossMatchingPolicy::class)
            );
        });

        // Bind the Application Service (optional, Laravel can auto-resolve if no extra args)
        $this->app->singleton(ResolveSuperficialLosses::class, function ($app) {
            return new ResolveSuperficialLosses(
                $app->make(SuperficialLossResolver::class)
            );
        });

        $this->app->bind(
            TaxAssetStateBuilderInterface::class,
            PoolLedgerStateBuilder::class
        );

        // Market data provider — defaults to the Null implementation. When an
        // Alpha Vantage API key is configured, swap in the Alpha Vantage
        // provider (EOD quotes, 24h cached) instead.
        $this->app->bind(
            \App\Services\MarketData\MarketDataProviderInterface::class,
            function ($app) {
                $key = (string) config('services.alpha_vantage.key', '');

                if ($key !== '') {
                    return new \App\Services\MarketData\AlphaVantageMarketDataProvider(
                        http: $app->make(\Illuminate\Http\Client\Factory::class),
                        apiKey: $key,
                        baseUrl: (string) config('services.alpha_vantage.base_url', 'https://www.alphavantage.co/query'),
                        cacheTtlSeconds: (int) config('services.alpha_vantage.cache_ttl', 86_400),
                        timeoutSeconds: (int) config('services.alpha_vantage.timeout', 10),
                    );
                }

                return $app->make(\App\Services\MarketData\NullMarketDataProvider::class);
            }
        );

        View::addNamespace('layouts', resource_path('views/components/layouts'));
    }
}
