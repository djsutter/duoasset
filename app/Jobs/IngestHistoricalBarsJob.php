<?php

namespace App\Jobs;

use App\Services\Dashboard\DashboardCacheService;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously ingests missing or stale historical daily bars for a stock symbol,
 * coordinating work to prevent duplicate upstream requests across concurrent users.
 */
class IngestHistoricalBarsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public string $symbol,
        public string $from,
        public string $to
    ) {
        $this->symbol = strtoupper(trim($symbol));
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->symbol;
    }

    /**
     * Dispatch ingestion only if an ingestion job for this symbol is not on an active cooldown.
     * Job deduplication is handled natively via ShouldBeUnique.
     */
    public static function dispatchIfNeeded(string $symbol, string $from, string $to): bool
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return false;
        }

        if (Cache::has("bars_ingest:cooldown:{$symbol}")) {
            return false;
        }

        static::dispatch($symbol, $from, $to);

        return true;
    }

    public function handle(MarketDataProvider $provider, DashboardCacheService $cacheService): void
    {
        $symbol = strtoupper(trim($this->symbol));
        if ($symbol === '') {
            return;
        }

        $fromDate = CarbonImmutable::parse($this->from);
        $toDate = CarbonImmutable::parse($this->to);

        try {
            $bars = $provider->historicalDailyBars($symbol, $fromDate, $toDate);

            $errors = method_exists($provider, 'lastErrors') ? $provider->lastErrors() : [];

            $isStillMissing = method_exists($provider, 'isDailyBarsStaleOrMissing')
                ? $provider->isDailyBarsStaleOrMissing($symbol, $fromDate, $toDate)
                : false;

            if (! empty($errors) || empty($bars) || $isStillMissing) {
                // Apply a 5-minute cooldown to avoid request storms on upstream failure, no data,
                // or unresolved gaps (e.g. symbol didn't exist for full requested history).
                Cache::put("bars_ingest:cooldown:{$symbol}", true, now()->addMinutes(5));
            } else {
                Cache::forget("bars_ingest:cooldown:{$symbol}");
            }

            // Invalidate gainers for affected users if any bars were stored
            if (! empty($bars)) {
                $cacheService->invalidateGainersForSymbol($symbol);
            }
        } catch (Throwable $e) {
            Log::warning('historical_bars.ingest_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            Cache::put("bars_ingest:cooldown:{$symbol}", true, now()->addMinutes(5));
        }
    }
}
