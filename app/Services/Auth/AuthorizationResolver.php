<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class AuthorizationResolver
{
    /**
     * Resolve the authorization principal for the given user or current request.
     *
     * Important: Resolving the Anonymous User for unauthenticated visitors NEVER
     * logs in or mutates the authentication guard (Auth::check() remains false).
     */
    public function resolvePrincipal(?User $user = null): User
    {
        if ($user !== null) {
            if ($user->exists) {
                $fresh = User::find($user->id);
                if ($fresh !== null) {
                    if (Auth::check() && Auth::id() === $fresh->id) {
                        Auth::setUser($fresh);
                    }

                    return $fresh;
                }
            }

            return $user;
        }

        if (Auth::check()) {
            /** @var User|null $authUser */
            $authUser = Auth::user();
            if ($authUser !== null) {
                if ($authUser->exists) {
                    $fresh = User::find($authUser->id);
                    if ($fresh !== null) {
                        Auth::setUser($fresh);

                        return $fresh;
                    }
                }

                return $authUser;
            }
        }

        return User::ensureAnonymousUser();
    }

    public function isAuthenticated(?User $user = null): bool
    {
        if ($user !== null) {
            return $user->exists && ! $user->isAnonymousUser();
        }

        if (! Auth::check()) {
            return false;
        }

        /** @var User|null $authUser */
        $authUser = Auth::user();

        return $authUser !== null && ! $authUser->isAnonymousUser();
    }

    public function canAccessStocks(?User $user = null): bool
    {
        return $this->resolvePrincipal($user)->hasStockAccess();
    }

    public function canAccessCrypto(?User $user = null): bool
    {
        return $this->resolvePrincipal($user)->hasCryptoAccess();
    }

    public function canAccessFutures(?User $user = null): bool
    {
        return $this->resolvePrincipal($user)->hasFuturesAccess();
    }

    public function canAccessContext(string $context, ?User $user = null): bool
    {
        return match (strtolower(trim($context))) {
            'stocks' => $this->canAccessStocks($user),
            'crypto' => $this->canAccessCrypto($user),
            'futures' => $this->canAccessFutures($user),
            default => false,
        };
    }

    /**
     * Administrative access requires BOTH an authenticated non-anonymous session
     * AND the Administrator role.
     */
    public function canAccessAdmin(?User $user = null): bool
    {
        $target = $user ?? (Auth::check() ? Auth::user() : null);
        if (! $target instanceof User || $target->isAnonymousUser()) {
            return false;
        }

        if ($target->exists) {
            $target = User::find($target->id) ?? $target;
        }

        return ! $target->isAnonymousUser() && $target->isAdmin();
    }

    /**
     * @return list<string>
     */
    public function permittedContexts(?User $user = null): array
    {
        return $this->resolvePrincipal($user)->permittedViewModes();
    }

    public function canToggleViewMode(?User $user = null): bool
    {
        return $this->resolvePrincipal($user)->canToggleViewMode();
    }

    /**
     * Resolve the active dashboard context for the current visitor, falling back
     * to an authorized context if previously selected context permissions were revoked.
     *
     * Returns null when the visitor has no permitted dashboard contexts at all.
     */
    public function resolveActiveContext(?User $user = null): ?string
    {
        $principal = $this->resolvePrincipal($user);
        $permitted = $principal->permittedViewModes();

        if (empty($permitted)) {
            return null;
        }

        if ($principal->isAnonymousUser()) {
            $sessionMode = session('anonymous_view_mode');
            if (is_string($sessionMode) && in_array($sessionMode, $permitted, true)) {
                return $sessionMode;
            }

            $fallback = in_array('futures', $permitted, true) && count($permitted) === 1
                ? 'futures'
                : $permitted[0];

            session(['anonymous_view_mode' => $fallback]);

            return $fallback;
        }

        $saved = strtolower(trim((string) ($principal->view_mode ?: 'stocks')));
        if (in_array($saved, $permitted, true)) {
            return $saved;
        }

        $fallback = $permitted[0];
        if ($principal->exists && $principal->view_mode !== $fallback) {
            $principal->view_mode = $fallback;
            $principal->save();
        }

        return $fallback;
    }

    /**
     * Validate and persist a dashboard context selection for the current visitor.
     */
    public function selectContext(string $mode, ?User $user = null): string
    {
        $normalized = strtolower(trim($mode));

        if (! in_array($normalized, User::VALID_VIEW_MODES, true)) {
            throw new InvalidArgumentException("Invalid view mode [{$mode}]. Must be 'stocks', 'crypto', or 'futures'.");
        }

        $principal = $this->resolvePrincipal($user);

        if (! $principal->canToggleViewMode() || ! in_array($normalized, $principal->permittedViewModes(), true)) {
            abort(403, 'Unauthorized perspective switch.');
        }

        if ($principal->isAnonymousUser()) {
            session(['anonymous_view_mode' => $normalized]);

            return $normalized;
        }

        $principal->setViewMode($normalized);

        return $normalized;
    }
}
