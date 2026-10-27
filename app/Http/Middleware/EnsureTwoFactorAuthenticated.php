<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('login.id')
            && ! $request->routeIs('two-factor.challenge')
            && ! $request->routeIs('logout')
            && ! $this->isTwoFactorChallengeLivewireRequest($request)
        ) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }

    /**
     * Determine if the request is a Livewire update specifically for the two-factor challenge component.
     */
    protected function isTwoFactorChallengeLivewireRequest(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        if (! $request->routeIs('default-livewire.update', 'livewire.update') && ! $request->hasHeader('X-Livewire')) {
            return false;
        }

        $components = $request->input('components') ?? $request->json('components');

        if (! is_array($components) || empty($components)) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                return false;
            }

            $snapshot = $component['snapshot'] ?? null;
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }

            if (! is_array($snapshot)) {
                return false;
            }

            $name = $snapshot['memo']['name'] ?? null;
            if (! in_array($name, ['auth.two-factor-challenge', 'two-factor-challenge'], true)) {
                return false;
            }
        }

        return true;
    }
}
