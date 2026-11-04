<?php

namespace App\Services\Dashboard;

use App\Jobs\PrefetchUserDashboardJob;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Driver-agnostic caching for the dashboard cards using a stale-while-revalidate envelope.
 *
 * Cache entries are stored indefinitely in the envelope structure:
 *   ['data' => $payload, 'stale_at' => $staleAtTimestamp]
 *
 * - Top scoring setups / EPS surprises: cached until invalidated by background scanner jobs.
 * - Money flows / user watchlist gainers: stale_at driven by the user's trading session
 *   (15 minutes in session, until next market open otherwise).
 */
class DashboardCacheService
{
    public const KEY_TOP_SCORING_SETUPS = 'dash_top_scoring_setups';

    public const KEY_HIGHEST_EPS_SURPRISES = 'dash_highest_eps_surprises';

    public const SLOT_GAINERS = 'gainers';

    public const SLOT_LOSERS = 'losers';

    public const ACTIVE_TTL = 900;

    public const SCANNER_TTL = 604800;

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

    public static function prefetchLockKey(int $userId): string
    {
        return "dash_prefetch_lock_{$userId}";
    }

    public function acquirePrefetchLock(int $userId, int $seconds = 60): bool
    {
        $key = self::prefetchLockKey($userId);
        try {
            $lock = Cache::lock($key, $seconds);

            return (bool) $lock->get();
        } catch (\Throwable) {
            return (bool) Cache::add($key, true, $seconds);
        }
    }

    public function releasePrefetchLock(int $userId): void
    {
        $key = self::prefetchLockKey($userId);
        try {
            $lock = Cache::lock($key, 60);
            $lock->forceRelease();
        } catch (\Throwable) {
            Cache::forget($key);
        }
    }

    public function isUserDashboardStale(User $user): bool
    {
        $gainersKey = self::userGainersKey($user->id);
        $gainersEnvelope = $this->getEnvelope($gainersKey);
        if ($gainersEnvelope !== null && $this->isStale($gainersEnvelope)) {
            return true;
        }

        $flowGainersEnvelope = $this->getEnvelope(self::moneyFlowsKey(self::SLOT_GAINERS));
        if ($flowGainersEnvelope !== null && $this->isStale($flowGainersEnvelope)) {
            return true;
        }

        $flowLosersEnvelope = $this->getEnvelope(self::moneyFlowsKey(self::SLOT_LOSERS));
        if ($flowLosersEnvelope !== null && $this->isStale($flowLosersEnvelope)) {
            return true;
        }

        return false;
    }

    public function triggerPrefetchIfStale(User $user): bool
    {
        if ($this->isUserDashboardStale($user)) {
            if ($this->acquirePrefetchLock($user->id, 60)) {
                PrefetchUserDashboardJob::dispatch($user->id);

                return true;
            }
        }

        return false;
    }

    /**
     * Stale-while-revalidate cache envelope wrapper.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return array{data: T, stale_at: int}
     */
    public function rememberStale(string $key, int $ttlSeconds, \Closure $callback): array
    {
        $envelope = $this->getEnvelope($key);
        if ($envelope !== null) {
            return $envelope;
        }

        $data = $callback();

        return $this->putEnvelope($key, $data, $ttlSeconds);
    }

    /**
     * Store payload into a cache envelope with indefinite retention.
     *
     * @template T
     *
     * @param  T  $data
     * @return array{data: T, stale_at: int}
     */
    public function putEnvelope(string $key, mixed $data, int $ttlSeconds): array
    {
        $envelope = [
            'data' => $data,
            'stale_at' => now()->timestamp + $ttlSeconds,
        ];

        Cache::forever($key, $envelope);

        return $envelope;
    }

    /**
     * Retrieve raw cache envelope if present.
     *
     * @return array{data: mixed, stale_at: int}|null
     */
    public function getEnvelope(string $key): ?array
    {
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('data', $cached) && array_key_exists('stale_at', $cached)) {
            return $cached;
        }

        return null;
    }

    /**
     * Determine if a cache envelope or key is stale.
     */
    public function isStale(array|string $envelopeOrKey): bool
    {
        if (is_string($envelopeOrKey)) {
            $envelope = $this->getEnvelope($envelopeOrKey);
            if ($envelope === null || ! isset($envelope['stale_at'])) {
                return true;
            }

            return now()->timestamp >= (int) $envelope['stale_at'];
        }

        if (! isset($envelopeOrKey['stale_at'])) {
            return true;
        }

        return now()->timestamp >= (int) $envelopeOrKey['stale_at'];
    }

    public function topScoringSetups(): Collection
    {
        $envelope = $this->rememberStale(
            self::KEY_TOP_SCORING_SETUPS,
            self::SCANNER_TTL,
            fn () => $this->queries->topScoringSetups()
        );

        return $this->unwrapCollection($envelope['data']);
    }

    public function highestEpsSurprises(): Collection
    {
        $envelope = $this->rememberStale(
            self::KEY_HIGHEST_EPS_SURPRISES,
            self::SCANNER_TTL,
            fn () => $this->queries->highestEpsSurprises()
        );

        return $this->unwrapCollection($envelope['data']);
    }

    public function moneyFlowGainers(?User $user): Collection
    {
        $envelope = $this->rememberStale(
            self::moneyFlowsKey(self::SLOT_GAINERS),
            $this->ttlFor($user),
            fn () => $this->queries->moneyFlowGainers()
        );

        return $this->unwrapCollection($envelope['data']);
    }

    public function moneyFlowLosers(?User $user): Collection
    {
        $envelope = $this->rememberStale(
            self::moneyFlowsKey(self::SLOT_LOSERS),
            $this->ttlFor($user),
            fn () => $this->queries->moneyFlowLosers()
        );

        return $this->unwrapCollection($envelope['data']);
    }

    public function userGainers(User $user, ?Watchlist $watchlist, MarketDataProvider $provider): Collection
    {
        if ($watchlist === null || $watchlist->items->isEmpty()) {
            return collect();
        }

        $envelope = $this->rememberStale(
            self::userGainersKey($user->id),
            $this->ttlFor($user),
            fn () => $this->queries->highestGainingStocks($watchlist, $provider)
        );

        return $this->unwrapCollection($envelope['data']);
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

    /**
     * Force refresh and overwrite all cached envelopes for the user with fresh timestamps.
     */
    public function refreshUserDashboard(User $user, ?Watchlist $watchlist, MarketDataProvider $provider): void
    {
        $ttl = $this->ttlFor($user);

        $this->putEnvelope(
            self::KEY_TOP_SCORING_SETUPS,
            $this->queries->topScoringSetups(),
            self::SCANNER_TTL
        );

        $this->putEnvelope(
            self::KEY_HIGHEST_EPS_SURPRISES,
            $this->queries->highestEpsSurprises(),
            self::SCANNER_TTL
        );

        $this->putEnvelope(
            self::moneyFlowsKey(self::SLOT_GAINERS),
            $this->queries->moneyFlowGainers(),
            $ttl
        );

        $this->putEnvelope(
            self::moneyFlowsKey(self::SLOT_LOSERS),
            $this->queries->moneyFlowLosers(),
            $ttl
        );

        if ($watchlist !== null && $watchlist->items->isNotEmpty()) {
            $this->putEnvelope(
                self::userGainersKey($user->id),
                $this->queries->highestGainingStocks($watchlist, $provider),
                $ttl
            );
        }
    }

    private function unwrapCollection(mixed $data): Collection
    {
        return $data instanceof Collection ? $data : collect($data);
    }

    private function ttlFor(?User $user): int
    {
        $timezone = $user?->timezone() ?? TradingSessionService::DEFAULT_TIMEZONE;

        return $this->sessions->getTradingHoursTtl($timezone, self::ACTIVE_TTL);
    }
}
