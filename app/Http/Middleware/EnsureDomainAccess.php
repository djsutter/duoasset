<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthorizationResolver;
use App\Services\Stocks\InstrumentClassifier;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDomainAccess
{
    public function __construct(
        private readonly AuthorizationResolver $authResolver,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $domain): Response
    {
        $user = $request->user();

        if ($user !== null) {
            if ($user->isAnonymousUser()) {
                abort(403, 'Anonymous system identity cannot authenticate.');
            }

            if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
                return $request->expectsJson()
                    ? abort(403, 'Your email address is not verified.')
                    : redirect()->route('verification.notice');
            }
        }

        $domainNormalized = strtolower(trim($domain));

        if ($domainNormalized === 'dashboard') {
            if ($user !== null) {
                $this->authResolver->resolveActiveContext($user);

                return $next($request);
            }

            $activeContext = $this->authResolver->resolveActiveContext(null);
            if ($activeContext !== null) {
                return $next($request);
            }

            return $request->expectsJson()
                ? abort(401, 'Unauthenticated.')
                : redirect()->guest(route('login'));
        }

        if ($domainNormalized === 'futures') {
            $rawSymbol = (string) $request->route('symbol', '');
            if ($rawSymbol !== '' && $request->routeIs('futures.show')) {
                $classifier = app(InstrumentClassifier::class);
                if (! $classifier->isFutures($rawSymbol) && ($user !== null || $this->authResolver->canAccessStocks(null))) {
                    return $next($request);
                }
            }

            if (! $this->authResolver->canAccessFutures($user)) {
                abort(403, 'Access denied: your account does not have permission to access the futures domain.');
            }

            if ($request->isMethod('GET') && $this->authResolver->canToggleViewMode($user)) {
                if ($user !== null && $user->view_mode !== 'futures') {
                    $user->setViewMode('futures');
                } elseif ($user === null) {
                    session(['anonymous_view_mode' => 'futures']);
                }
            }

            return $next($request);
        }

        if (in_array($domainNormalized, ['stocks', 'equities'], true)) {
            $rawSymbol = (string) $request->route('symbol', '');
            if ($rawSymbol !== '' && ($request->routeIs('stock.show') || $request->routeIs('stocks.show'))) {
                $classifier = app(InstrumentClassifier::class);
                if ($classifier->isFutures($rawSymbol) && ($user !== null || $this->authResolver->canAccessFutures(null))) {
                    return $next($request);
                }
            }

            if ($user === null && ! $this->authResolver->canAccessStocks(null)) {
                return $request->expectsJson()
                    ? abort(401, 'Unauthenticated.')
                    : redirect()->guest(route('login'));
            }

            if (! $this->authResolver->canAccessStocks($user)) {
                abort(403, 'Access denied: your account does not have permission to access the equities/stock domain.');
            }

            if ($request->isMethod('GET') && $this->authResolver->canToggleViewMode($user)) {
                if ($user !== null && $user->view_mode !== 'stocks') {
                    $user->setViewMode('stocks');
                } elseif ($user === null) {
                    session(['anonymous_view_mode' => 'stocks']);
                }
            }
        } elseif ($domainNormalized === 'crypto') {
            if ($user === null && ! $this->authResolver->canAccessCrypto(null)) {
                return $request->expectsJson()
                    ? abort(401, 'Unauthenticated.')
                    : redirect()->guest(route('login'));
            }

            if (! $this->authResolver->canAccessCrypto($user)) {
                abort(403, 'Access denied: your account does not have permission to access the crypto domain.');
            }

            if ($request->isMethod('GET') && $this->authResolver->canToggleViewMode($user)) {
                if ($user !== null && $user->view_mode !== 'crypto') {
                    $user->setViewMode('crypto');
                } elseif ($user === null) {
                    session(['anonymous_view_mode' => 'crypto']);
                }
            }
        } else {
            abort(403, "Invalid domain specified [{$domain}].");
        }

        return $next($request);
    }
}
