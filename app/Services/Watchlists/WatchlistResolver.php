<?php

namespace App\Services\Watchlists;

use App\Models\User;
use App\Models\Watchlist;

class WatchlistResolver
{
    /**
     * Resolve the active watchlist for a user according to priority:
     * 1. Personal watchlist marked is_default = true (where is_global = false).
     * 2. Oldest personal watchlist (ordered deterministically by id asc).
     * 3. Administrator-controlled global watchlist (is_global = true).
     * 4. Null if no watchlist exists.
     */
    public function resolveForUser(?User $user): ?Watchlist
    {
        if (! $user || $user->isAnonymousUser()) {
            return null;
        }

        // 1. Personal watchlist marked as default (explicitly excluding global)
        $default = Watchlist::query()
            ->where('user_id', $user->id)
            ->where('is_global', false)
            ->where('is_default', true)
            ->with(['items.stock'])
            ->first();

        if ($default) {
            return $default;
        }

        // 2. Oldest personal watchlist (ordered deterministically by id asc)
        $oldestPersonal = Watchlist::query()
            ->where('user_id', $user->id)
            ->where('is_global', false)
            ->orderBy('id')
            ->with(['items.stock'])
            ->first();

        if ($oldestPersonal) {
            return $oldestPersonal;
        }

        // 3. Administrator-controlled global watchlist
        return Watchlist::query()
            ->where('is_global', true)
            ->orderBy('id')
            ->with(['items.stock'])
            ->first();
    }
}
