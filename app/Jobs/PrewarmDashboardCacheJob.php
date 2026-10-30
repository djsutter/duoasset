<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Watchlist;
use App\Services\Dashboard\DashboardCacheService;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warms the dashboard caches (market-wide setups, EPS surprises, money flows and the
 * user's default watchlist gainers) while the user is still on the 2FA challenge screen.
 */
class PrewarmDashboardCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $userId) {}

    public function handle(DashboardCacheService $cache, MarketDataProvider $provider): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        try {
            $watchlist = Watchlist::query()
                ->where('user_id', $user->id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->with(['items.stock'])
                ->first();

            $cache->warmForUser($user, $watchlist, $provider);
        } catch (Throwable $e) {
            Log::warning('dashboard.prewarm_failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
