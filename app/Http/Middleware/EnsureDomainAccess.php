<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDomainAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $domain
     */
    public function handle(Request $request, Closure $next, string $domain): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized domain access.');
        }

        $domainNormalized = strtolower(trim($domain));

        if (in_array($domainNormalized, ['stocks', 'equities'], true)) {
            if (! $user->hasStockAccess()) {
                abort(403, 'Access denied: your account does not have permission to access the equities/stock domain.');
            }

            if ($request->isMethod('GET') && $user->canToggleViewMode() && $user->view_mode !== 'stocks') {
                $user->setViewMode('stocks');
            }
        } elseif ($domainNormalized === 'crypto') {
            if (! $user->hasCryptoAccess()) {
                abort(403, 'Access denied: your account does not have permission to access the crypto domain.');
            }

            if ($request->isMethod('GET') && $user->canToggleViewMode() && $user->view_mode !== 'crypto') {
                $user->setViewMode('crypto');
            }
        } else {
            abort(403, "Invalid domain specified [{$domain}].");
        }

        return $next($request);
    }
}
