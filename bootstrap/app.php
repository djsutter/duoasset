<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\MarketDataServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        $middleware->alias([
            'domain' => \App\Http\Middleware\EnsureDomainAccess::class,
            'EnsureDomainAccess' => \App\Http\Middleware\EnsureDomainAccess::class,
            '2fa' => \App\Http\Middleware\EnsureTwoFactorAuthenticated::class,
            'api.market_data' => \App\Http\Middleware\EnsureApiMarketDataAccess::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureTwoFactorAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
