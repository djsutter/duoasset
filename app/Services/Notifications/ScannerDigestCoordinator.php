<?php

namespace App\Services\Notifications;

use App\Jobs\PrepareScannerRunDigests;
use App\Models\EarningsAlert;
use App\Models\EarningsScanRun;
use App\Models\NotificationDigestDelivery;
use App\Models\Setting;
use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Services\Dashboard\DashboardCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Coordinates multi-step or sliced scanner runs (such as nightly exchange/letter loops)
 * so that all slice evaluations attach to a single master scan run and produce
 * exactly one complete digest email per subscriber after collection finishes.
 */
class ScannerDigestCoordinator
{
    /**
     * Normalize category identifier to canonical NotificationDigestDelivery constant.
     */
    public function normalizeCategory(string $category): string
    {
        $norm = strtolower(trim($category));

        return match ($norm) {
            'setup', 'setups', 'setup_scoring', 'setup-scoring', 'buy_setups', 'buy-setups' => NotificationDigestDelivery::CATEGORY_SETUP_SCORING,
            'eps', 'earnings', 'eps_surprise', 'eps-surprise', 'earnings_surprises' => NotificationDigestDelivery::CATEGORY_EPS_SURPRISE,
            default => throw new InvalidArgumentException("Unsupported digest category [{$category}]. Expected 'setup_scoring' or 'eps_surprise'."),
        };
    }

    public static function cacheKey(string $category): string
    {
        return "digest_collection:active_run:{$category}";
    }

    public static function settingKey(string $category): string
    {
        return "digest_collection.active_run.{$category}";
    }

    /**
     * Start (or resume) a coordinated digest collection session for the given category.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function startCollecting(string $category, array $metadata = []): EarningsScanRun|StockBuySetupRun
    {
        $cat = $this->normalizeCategory($category);

        $existing = $this->activeCollectionRun($cat);
        if ($existing !== null) {
            return $existing;
        }

        if ($cat === NotificationDigestDelivery::CATEGORY_SETUP_SCORING) {
            StockBuySetupAlert::ensureRunTrackingSchema();

            $run = StockBuySetupRun::create([
                'started_at' => now(),
                'status' => 'collecting',
                'trigger' => 'coordinated',
                'symbols_considered' => 0,
                'scored_count' => 0,
                'matched_count' => 0,
                'rejected_count' => 0,
                'error_count' => 0,
                'summary_data' => array_merge([
                    'coordinated' => true,
                    'slices_completed' => 0,
                ], $metadata),
            ]);

            $this->storeActiveRunId($cat, $run->id);

            Log::info('digest_coordinator.started', [
                'category' => $cat,
                'scan_run_id' => $run->id,
            ]);

            return $run;
        }

        EarningsScanRun::ensureRunTrackingSchema();

        $run = EarningsScanRun::create([
            'started_at' => now(),
            'status' => 'collecting',
            'trigger' => 'coordinated',
            'from_date' => $metadata['from_date'] ?? null,
            'to_date' => $metadata['to_date'] ?? null,
            'events_processed' => 0,
            'alerts_created' => 0,
            'error_count' => 0,
        ]);

        $this->storeActiveRunId($cat, $run->id);

        Log::info('digest_coordinator.started', [
            'category' => $cat,
            'scan_run_id' => $run->id,
        ]);

        return $run;
    }

    /**
     * Return the active collecting scan_run_id for the category, or null if not collecting.
     */
    public function activeCollectionRunId(string $category): ?int
    {
        return $this->activeCollectionRun($category)?->id;
    }

    /**
     * Return the active collecting run model if a collection session is currently open.
     */
    public function activeCollectionRun(string $category): EarningsScanRun|StockBuySetupRun|null
    {
        $cat = $this->normalizeCategory($category);
        $rawId = Cache::get(self::cacheKey($cat));

        if ($rawId === null) {
            try {
                if (Schema::hasTable('settings')) {
                    $rawId = Setting::get(self::settingKey($cat));
                }
            } catch (Throwable) {
                $rawId = null;
            }
        }

        if (! is_numeric($rawId) || (int) $rawId <= 0) {
            return null;
        }

        $runId = (int) $rawId;

        if ($cat === NotificationDigestDelivery::CATEGORY_SETUP_SCORING) {
            if (! Schema::hasTable('stock_buy_setup_runs')) {
                return null;
            }
            $run = StockBuySetupRun::find($runId);
            if ($run && $run->status === 'collecting' && $run->completed_at === null) {
                return $run;
            }

            $this->clearActiveRunId($cat);

            return null;
        }

        if (! Schema::hasTable('earnings_scan_runs')) {
            return null;
        }
        $run = EarningsScanRun::find($runId);
        if ($run && $run->status === 'collecting' && $run->completed_at === null) {
            return $run;
        }

        $this->clearActiveRunId($cat);

        return null;
    }

    public function isCollecting(string $category): bool
    {
        return $this->activeCollectionRun($category) !== null;
    }

    /**
     * Merge slice statistics into a coordinated StockBuySetupRun without completing it or sending partial digests.
     *
     * @param  array<string, mixed>  $summary
     */
    public function recordSetupSliceSummary(
        int $runId,
        int $symbolsConsidered,
        array $summary,
        float $elapsedSeconds,
    ): void {
        DB::transaction(function () use ($runId, $symbolsConsidered, $summary, $elapsedSeconds) {
            /** @var StockBuySetupRun|null $run */
            $run = StockBuySetupRun::lockForUpdate()->find($runId);
            if (! $run) {
                return;
            }

            $run->symbols_considered = (int) $run->symbols_considered + $symbolsConsidered;
            $run->scored_count = (int) $run->scored_count + (int) ($summary['processed'] ?? 0);
            $run->matched_count = (int) $run->matched_count + (int) ($summary['matched'] ?? 0);
            $run->rejected_count = (int) $run->rejected_count + (int) ($summary['rejected'] ?? 0);
            $run->error_count = (int) $run->error_count + (int) ($summary['errors'] ?? 0);

            $rawReasons = is_array($run->raw_reasons) ? $run->raw_reasons : [];
            $catReasons = is_array($run->categorized_reasons) ? $run->categorized_reasons : [];

            foreach (($summary['reasons'] ?? []) as $reason => $count) {
                $rawReasons[$reason] = ($rawReasons[$reason] ?? 0) + (int) $count;
                $cat = StockBuySetupRun::categorizeReason((string) $reason);
                $catReasons[$cat] = ($catReasons[$cat] ?? 0) + (int) $count;
            }

            $run->raw_reasons = $rawReasons;
            $run->categorized_reasons = $catReasons;

            $top = is_array($run->top_matches) ? $run->top_matches : [];
            foreach (($summary['top'] ?? []) as $item) {
                if (is_array($item)) {
                    $top[] = $item;
                }
            }
            usort($top, fn ($a, $b) => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));
            $run->top_matches = array_slice($top, 0, 20);

            $summaryData = is_array($run->summary_data) ? $run->summary_data : [];
            $summaryData['slices_completed'] = (int) ($summaryData['slices_completed'] ?? 0) + 1;
            $summaryData['elapsed_seconds'] = round(((float) ($summaryData['elapsed_seconds'] ?? 0)) + $elapsedSeconds, 2);
            $summaryData['created'] = (int) ($summaryData['created'] ?? 0) + (int) ($summary['created'] ?? 0);
            $summaryData['existing'] = (int) ($summaryData['existing'] ?? 0) + (int) ($summary['existing'] ?? 0);
            $summaryData['screener_rejected_count'] = (int) ($summaryData['screener_rejected_count'] ?? 0) + (int) ($summary['screener_rejected_count'] ?? 0);
            $run->summary_data = $summaryData;

            $run->save();
        });
    }

    /**
     * Merge slice statistics into a coordinated EarningsScanRun without completing it or sending partial digests.
     */
    public function recordEarningsSliceSummary(
        int $runId,
        int $processed,
        int $errors,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): void {
        DB::transaction(function () use ($runId, $processed, $errors, $fromDate, $toDate) {
            /** @var EarningsScanRun|null $run */
            $run = EarningsScanRun::lockForUpdate()->find($runId);
            if (! $run) {
                return;
            }

            $run->events_processed = (int) $run->events_processed + $processed;
            $run->error_count = (int) $run->error_count + $errors;

            if ($fromDate !== null) {
                $existingFrom = $run->from_date?->toDateString();
                if ($existingFrom === null || $fromDate < $existingFrom) {
                    $run->from_date = $fromDate;
                }
            }

            if ($toDate !== null) {
                $existingTo = $run->to_date?->toDateString();
                if ($existingTo === null || $toDate > $existingTo) {
                    $run->to_date = $toDate;
                }
            }

            if (Schema::hasTable('earnings_alerts') && Schema::hasColumn('earnings_alerts', 'scan_run_id')) {
                $run->alerts_created = EarningsAlert::where('scan_run_id', $run->id)->count();
            }

            $run->save();
        });
    }

    /**
     * Complete the active collection run and dispatch a single consolidated digest preparation job.
     */
    public function prepareDigest(string $category, ?int $scanRunId = null, bool $sync = false): ?int
    {
        $cat = $this->normalizeCategory($category);
        $targetRunId = $scanRunId ?? $this->activeCollectionRunId($cat);

        $this->clearActiveRunId($cat);

        if ($targetRunId === null) {
            // Fallback to most recent uncompleted collecting run if present
            if ($cat === NotificationDigestDelivery::CATEGORY_SETUP_SCORING && Schema::hasTable('stock_buy_setup_runs')) {
                $latest = StockBuySetupRun::query()
                    ->where('status', 'collecting')
                    ->orderByDesc('id')
                    ->first();
                $targetRunId = $latest?->id;
            } elseif ($cat === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE && Schema::hasTable('earnings_scan_runs')) {
                $latest = EarningsScanRun::query()
                    ->where('status', 'collecting')
                    ->orderByDesc('id')
                    ->first();
                $targetRunId = $latest?->id;
            }
        }

        if ($targetRunId === null) {
            return null;
        }

        if ($cat === NotificationDigestDelivery::CATEGORY_SETUP_SCORING) {
            $run = StockBuySetupRun::find($targetRunId);
            if (! $run) {
                return null;
            }

            $alertCount = ( Schema::hasTable('stock_buy_setup_alerts') && Schema::hasColumn('stock_buy_setup_alerts', 'scan_run_id') )
                ? StockBuySetupAlert::where('scan_run_id', $run->id)->count()
                : (int) $run->matched_count;

            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'matched_count' => max((int) $run->matched_count, $alertCount),
            ]);

            app(DashboardCacheService::class)->invalidateTopScoringSetups();
        } else {
            $run = EarningsScanRun::find($targetRunId);
            if (! $run) {
                return null;
            }

            $alertCount = ( Schema::hasTable('earnings_alerts') && Schema::hasColumn('earnings_alerts', 'scan_run_id') )
                ? EarningsAlert::where('scan_run_id', $run->id)->count()
                : (int) $run->alerts_created;

            $run->update([
                'status' => EarningsScanRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'alerts_created' => $alertCount,
            ]);

            app(DashboardCacheService::class)->invalidateEpsSurprises();
        }

        if ($sync) {
            PrepareScannerRunDigests::dispatchSync($cat, $targetRunId);
        } else {
            PrepareScannerRunDigests::dispatch($cat, $targetRunId);
        }

        Log::info('digest_coordinator.prepared', [
            'category' => $cat,
            'scan_run_id' => $targetRunId,
            'sync' => $sync,
        ]);

        return $targetRunId;
    }

    protected function storeActiveRunId(string $category, int $runId): void
    {
        Cache::forever(self::cacheKey($category), $runId);
        try {
            if (Schema::hasTable('settings')) {
                Setting::set(self::settingKey($category), (string) $runId);
            }
        } catch (Throwable) {
        }
    }

    public function clearActiveRunId(string $category): void
    {
        $cat = $this->normalizeCategory($category);
        Cache::forget(self::cacheKey($cat));
        try {
            if (Schema::hasTable('settings')) {
                Setting::where('key', self::settingKey($cat))->delete();
            }
        } catch (Throwable) {
        }
    }
}
