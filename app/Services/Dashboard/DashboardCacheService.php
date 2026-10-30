<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Models\Watchlist;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Driver-agnostic caching for the dashboard cards (uses only get/put/remember/forget).
 *
 * - Top scoring setups / EPS surprises: cached until the relevant scanner job completes.
 * - Money flows / user watchlist gainers: TTL driven by the user's trading session
 *   (15 minutes in session, until next market open otherwise).
 */
class DashboardCacheService
{
    public const KEY_TOP_SCORING_SETUPS = 'dash_top_scoring_setups';

    public const KEY_HIGHEST_EPS_SURPRISES = 'dash_highest_eps_surprises';

    public const SLOT_GAINERS = 'gainers';

    public const SLOT_LOSERS = 'losers';

    public const ACTIVE_TTL = 900;

    public function __construct(
        private DashboardQueryService $queries,
        private TradingSessionService $sessions,
    ) {}

    public static function moneyFlowsKey(string $slot): string
    {
        return "dash_money_flows_{$slot}";
    }

    public static function userGainersKey(int $userId): string
    {
        return "dash_user_{$userId}_gainers";
    }

    public function topScoringSetups(): Collection
    {
        return Cache::rememberForever(self::KEY_TOP_SCORING_SETUPS, fn () => $this->queries->topScoringSetups());
    }

    public function highestEpsSurprises(): Collection
    {
        return Cache::rememberForever(self::KEY_HIGHEST_EPS_SURPRISES, fn () => $this->queries->highestEpsSurprises());
    }

    public function moneyFlowGainers(?User $user): Collection
    {
        return Cache::remember(
            self::moneyFlowsKey(self::SLOT_GAINERS),
            $this->ttlFor($user),
            fn () => $this->queries->moneyFlowGainers()
        );
    }

    public function moneyFlowLosers(?User $user): Collection
    {
        return Cache::remember(
            self::moneyFlowsKey(self::SLOT_LOSERS),
            $this->ttlFor($user),
            fn () => $this->queries->moneyFlowLosers()
        );
    }

    public function userGainers(User $user, ?Watchlist $watchlist, MarketDataProvider $provider): Collection
    {
        if ($watchlist === null || $watchlist->items->isEmpty()) {
            return collect();
        }

        return Cache::remember(
            self::userGainersKey($user->id),
            $this->ttlFor($user),
            fn () => $this->queries->highestGainingStocks($watchlist, $provider)
        );
    }

    public function invalidateTopScoringSetups(): void
    {
        Cache::forget(self::KEY_TOP_SCORING_SETUPS);
    }

    public function invalidateEpsSurprises(): void
    {
        Cache::forget(self::KEY_HIGHEST_EPS_SURPRISES);
    }

    public function invalidateMoneyFlows(): void
    {
        Cache::forget(self::moneyFlowsKey(self::SLOT_GAINERS));
        Cache::forget(self::moneyFlowsKey(self::SLOT_LOSERS));
    }

    public function invalidateUserGainers(int $userId): void
    {
        Cache::forget(self::userGainersKey($userId));
    }

    /**
     * Warm every dashboard card for the user (used by the login pre-warm job).
     */
    public function warmForUser(User $user, ?Watchlist $watchlist, MarketDataProvider $provider): void
    {
        $this->topScoringSetups();
        $this->highestEpsSurprises();
        $this->moneyFlowGainers($user);
        $this->moneyFlowLosers($user);
        $this->userGainers($user, $watchlist, $provider);
    }

    private function ttlFor(?User $user): int
    {
        $timezone = $user?->timezone() ?? TradingSessionService::DEFAULT_TIMEZONE;

        return $this->sessions->getTradingHoursTtl($timezone, self::ACTIVE_TTL);
    }
}
