<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiMarketDataAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Check API key via headers only (X-API-KEY or Bearer token).
        // URL query parameter authentication is explicitly disabled for security.
        $apiKey = $request->header('X-API-KEY') ?? $request->bearerToken();

        $configuredApiKey = config('market_data.api_key');

        if (! empty($apiKey) && ! empty($configuredApiKey) && hash_equals((string) $configuredApiKey, (string) $apiKey)) {
            $request->attributes->set('authenticated_via_api_key', true);

            return $next($request);
        }

        $authResolver = app(\App\Services\Auth\AuthorizationResolver::class);
        $rawSymbol = (string) $request->route('symbol', '');
        $isCommoditiesEndpoint = $request->is('api/v1/market-data/commodities*');
        $isFutures = $isCommoditiesEndpoint
            || ($rawSymbol !== '' && app(\App\Services\Stocks\InstrumentClassifier::class)->isFutures($rawSymbol));

        // 2. Check unauthenticated visitor (allow only if Anonymous User has permission for the domain)
        if (! $user) {
            if ($isFutures && $authResolver->canAccessFutures(null)) {
                return $next($request);
            }
            if (! $isFutures && $rawSymbol !== '' && $authResolver->canAccessStocks(null)) {
                return $next($request);
            }

            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Unauthenticated: valid session or API key required.',
            ], 401);
        }

        // 3. Check domain authorization
        if ($isFutures) {
            if (! $authResolver->canAccessFutures($user)) {
                return response()->json([
                    'provider' => 'duoasset',
                    'error' => 'Forbidden: Futures Subscriber role required to access futures market data.',
                ], 403);
            }

            return $next($request);
        }

        if (! $authResolver->canAccessStocks($user)) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Forbidden: user account lacks permission to access market data.',
            ], 403);
        }

        return $next($request);
    }
}
