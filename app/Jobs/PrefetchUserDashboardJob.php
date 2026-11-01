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
 * Asynchronously refreshes dashboard cache envelopes in the background
 * when stale cache data is served to the user.
 */
class PrefetchUserDashboardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $userId) {}

    public function handle(DashboardCacheService $cache, MarketDataProvider $provider): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            $cache->releasePrefetchLock($this->userId);
            return;
        }

        try {
            $watchlist = Watchlist::query()
                ->where('user_id', $user->id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->with(['items.stock'])
                ->first();

            $cache->refreshUserDashboard($user, $watchlist, $provider);
        } catch (Throwable $e) {
            Log::warning('dashboard.prefetch_failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $cache->releasePrefetchLock($this->userId);
        }
    }
}
