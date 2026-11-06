<?php

namespace App\Jobs;

use App\Models\EarningsScanRun;
use App\Models\NotificationDigestDelivery;
use App\Models\NotificationOptOut;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Services\Notifications\EpsSurpriseDigestBuilder;
use App\Services\Notifications\SetupScoringDigestBuilder;
use App\Services\Notifications\UserWatchlistSymbolResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PrepareScannerRunDigests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $category,
        public int $scanRunId,
    ) {}

    public function handle(
        EpsSurpriseDigestBuilder $epsBuilder,
        SetupScoringDigestBuilder $setupBuilder,
        UserWatchlistSymbolResolver $watchlistResolver,
    ): void {
        if (! in_array($this->category, NotificationDigestDelivery::VALID_CATEGORIES, true)) {
            Log::warning('digest.coordinator_invalid_category', [
                'category' => $this->category,
                'scan_run_id' => $this->scanRunId,
            ]);

            return;
        }

        $runTable = $this->category === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE
            ? 'earnings_scan_runs'
            : 'stock_buy_setup_runs';

        if (! Schema::hasTable($runTable)) {
            Log::warning('digest.coordinator_missing_run_table', [
                'category' => $this->category,
                'scan_run_id' => $this->scanRunId,
                'table' => $runTable,
            ]);

            return;
        }

        $run = $this->category === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE
            ? EarningsScanRun::find($this->scanRunId)
            : StockBuySetupRun::find($this->scanRunId);

        if (! $run || $run->status !== 'completed') {
            Log::info('digest.coordinator_skipped_incomplete_run', [
                'category' => $this->category,
                'scan_run_id' => $this->scanRunId,
                'run_status' => $run?->status ?? 'missing',
            ]);

            return;
        }

        // 1. Compute global market-wide rankings ONCE per scanner run and persist snapshot.
        try {
            $snapshot = $this->category === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE
                ? $epsBuilder->buildAndStoreRunSnapshot($this->scanRunId)
                : $setupBuilder->buildAndStoreRunSnapshot($this->scanRunId);
        } catch (Throwable $e) {
            Log::error('digest.coordinator_failed', [
                'category' => $this->category,
                'scan_run_id' => $this->scanRunId,
                'msg' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (! empty($snapshot['is_run_empty'])) {
            Log::info('digest.coordinator_skipped_empty_run', [
                'category' => $this->category,
                'scan_run_id' => $this->scanRunId,
            ]);

            return;
        }

        if (! Schema::hasTable('notification_digest_deliveries')) {
            Log::warning('digest.coordinator_missing_deliveries_table', [
                'category' => $this->category,
                'scan_run_id' => $this->scanRunId,
            ]);

            return;
        }

        $optOutType = NotificationDigestDelivery::optOutTypeForCategory($this->category);
        $hasEpsDigestCol = Schema::hasColumn('users', 'email_eps_surprise_digest');
        $hasSetupDigestCol = Schema::hasColumn('users', 'email_setup_scoring_digest');

        $userQuery = User::query();
        if ($this->category === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE) {
            if ($hasEpsDigestCol) {
                $userQuery->where('email_eps_surprise_digest', true);
            }
            $userQuery->where('notify_eps_earnings', true);
        } else {
            if ($hasSetupDigestCol) {
                $userQuery->where('email_setup_scoring_digest', true);
            }
            $userQuery->where('notify_stock_buy_setup', true);
        }

        $eligibleCount = 0;
        $queuedCount = 0;
        $skippedOptOutCount = 0;
        $alreadyScheduledCount = 0;

        $selectColumns = ['id', 'email', 'notify_eps_earnings', 'notify_stock_buy_setup'];
        if ($hasEpsDigestCol) {
            $selectColumns[] = 'email_eps_surprise_digest';
        }
        if ($hasSetupDigestCol) {
            $selectColumns[] = 'email_setup_scoring_digest';
        }

        $userQuery->select($selectColumns)
            ->chunkById(200, function ($users) use (
                $optOutType,
                $watchlistResolver,
                &$eligibleCount,
                &$queuedCount,
                &$skippedOptOutCount,
                &$alreadyScheduledCount,
            ) {
                $emails = $users->pluck('email')
                    ->map(fn ($e) => strtolower(trim((string) $e)))
                    ->filter()
                    ->values()
                    ->all();

                $optedOutEmails = [];
                if (! empty($emails)) {
                    $optedOutEmails = NotificationOptOut::query()
                        ->where('item_type', $optOutType)
                        ->whereNotNull('opted_out_at')
                        ->whereIn('email', $emails)
                        ->pluck('email')
                        ->map(fn ($e) => strtolower(trim((string) $e)))
                        ->flip()
                        ->all();
                }

                $userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
                $watchlistsByUser = $watchlistResolver->forUsers($userIds);

                foreach ($users as $user) {
                    $normalizedEmail = strtolower(trim((string) $user->email));
                    if ($normalizedEmail === '' || isset($optedOutEmails[$normalizedEmail])) {
                        $skippedOptOutCount++;

                        continue;
                    }

                    $eligibleCount++;

                    $wasCreated = false;
                    try {
                        $delivery = NotificationDigestDelivery::firstOrCreate(
                            [
                                'scan_run_id' => $this->scanRunId,
                                'category' => $this->category,
                                'user_id' => $user->id,
                            ],
                            [
                                'status' => NotificationDigestDelivery::STATUS_QUEUED,
                                'attempts' => 0,
                                'watchlist_snapshot' => $watchlistsByUser[(int) $user->id] ?? [],
                            ],
                        );
                        $wasCreated = $delivery->wasRecentlyCreated;
                    } catch (UniqueConstraintViolationException) {
                        $delivery = NotificationDigestDelivery::query()
                            ->where('scan_run_id', $this->scanRunId)
                            ->where('category', $this->category)
                            ->where('user_id', $user->id)
                            ->first();
                    }

                    if (! $delivery) {
                        continue;
                    }

                    if ($wasCreated || ($delivery->status === NotificationDigestDelivery::STATUS_QUEUED && $delivery->claimed_at === null && $delivery->attempts === 0)) {
                        $queuedCount++;
                        SendUserNotificationDigest::dispatch($delivery->id);
                    } else {
                        $alreadyScheduledCount++;
                    }
                }
            });

        Log::info('digest.coordinator_completed', [
            'category' => $this->category,
            'scan_run_id' => $this->scanRunId,
            'eligible_subscribers' => $eligibleCount,
            'digests_queued' => $queuedCount,
            'digests_already_scheduled' => $alreadyScheduledCount,
            'digests_skipped_preferences' => $skippedOptOutCount,
        ]);
    }
}
