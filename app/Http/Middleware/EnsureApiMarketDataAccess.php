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
            return $next($request);
        }

        // 2. Check authenticated user
        if (! $user) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Unauthenticated: valid session or API key required.',
            ], 401);
        }

        // 3. Check stock domain authorization
        if (! $user->hasStockAccess()) {
            return response()->json([
                'provider' => 'duoasset',
                'error' => 'Forbidden: user account lacks permission to access market data.',
            ], 403);
        }

        return $next($request);
    }
}
