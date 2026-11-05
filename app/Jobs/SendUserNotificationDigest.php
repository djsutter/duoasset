<?php

namespace App\Jobs;

use App\Models\NotificationDigestDelivery;
use App\Models\User;
use App\Notifications\EpsSurpriseDigestNotification;
use App\Notifications\SetupScoringDigestNotification;
use App\Services\Notifications\EpsSurpriseDigestBuilder;
use App\Services\Notifications\SetupScoringDigestBuilder;
use App\Services\Notifications\UserWatchlistSymbolResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendUserNotificationDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(
        EpsSurpriseDigestBuilder $epsBuilder,
        SetupScoringDigestBuilder $setupBuilder,
        UserWatchlistSymbolResolver $watchlistResolver,
    ): void {
        // 1. Atomically claim the persistent outbox record to prevent concurrent duplicate sends.
        /** @var NotificationDigestDelivery|null $delivery */
        $delivery = DB::transaction(function () {
            $record = NotificationDigestDelivery::query()
                ->lockForUpdate()
                ->find($this->deliveryId);

            if (! $record) {
                return null;
            }

            if (in_array($record->status, [
                NotificationDigestDelivery::STATUS_SENT,
                NotificationDigestDelivery::STATUS_SKIPPED_UNSUBSCRIBED,
                NotificationDigestDelivery::STATUS_SKIPPED_EMPTY,
            ], true)) {
                return null;
            }

            // If another worker is actively processing this record within the last 5 minutes, do not claim.
            if (
                $record->status === NotificationDigestDelivery::STATUS_PROCESSING
                && $record->claimed_at !== null
                && $record->claimed_at->gt(now()->subMinutes(5))
            ) {
                return null;
            }

            $record->status = NotificationDigestDelivery::STATUS_PROCESSING;
            $record->claimed_at = now();
            $record->attempts = ((int) $record->attempts) + 1;
            $record->save();

            return $record;
        });

        if (! $delivery) {
            return;
        }

        try {
            // 2. Check current subscription preferences before preparing the digest.
            $user = User::find($delivery->user_id);
            if (! $user || ! $user->isSubscribedToDigest($delivery->category)) {
                $delivery->update([
                    'status' => NotificationDigestDelivery::STATUS_SKIPPED_UNSUBSCRIBED,
                    'claimed_at' => null,
                ]);

                Log::info('digest.delivery_skipped_unsubscribed', [
                    'delivery_id' => $delivery->id,
                    'scan_run_id' => $delivery->scan_run_id,
                    'category' => $delivery->category,
                    'user_id' => $delivery->user_id,
                ]);

                return;
            }

            // 3. Resolve or reuse the user's watchlist snapshot for consistent retry behavior.
            $watchedSymbols = $delivery->watchlist_snapshot;
            if (! is_array($watchedSymbols)) {
                $watchedSymbols = $watchlistResolver->forUser($user->id);
                $delivery->watchlist_snapshot = $watchedSymbols;
                $delivery->save();
            }

            // 4. Personalize digest by merging watchlist results with the precomputed run snapshot.
            $digestPayload = $delivery->category === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE
                ? $epsBuilder->buildForUser($delivery->scan_run_id, $watchedSymbols)
                : $setupBuilder->buildForUser($delivery->scan_run_id, $watchedSymbols);

            if (! empty($digestPayload['is_empty'])) {
                $delivery->update([
                    'status' => NotificationDigestDelivery::STATUS_SKIPPED_EMPTY,
                    'summary_counts' => $digestPayload['summary_counts'] ?? [],
                    'claimed_at' => null,
                ]);

                Log::info('digest.delivery_skipped_empty', [
                    'delivery_id' => $delivery->id,
                    'scan_run_id' => $delivery->scan_run_id,
                    'category' => $delivery->category,
                    'user_id' => $delivery->user_id,
                ]);

                return;
            }

            // 5. Re-verify subscription preferences immediately before sending (Section 11).
            $user->refresh();
            if (! $user->isSubscribedToDigest($delivery->category)) {
                $delivery->update([
                    'status' => NotificationDigestDelivery::STATUS_SKIPPED_UNSUBSCRIBED,
                    'summary_counts' => $digestPayload['summary_counts'] ?? [],
                    'claimed_at' => null,
                ]);

                Log::info('digest.delivery_skipped_unsubscribed', [
                    'delivery_id' => $delivery->id,
                    'scan_run_id' => $delivery->scan_run_id,
                    'category' => $delivery->category,
                    'user_id' => $delivery->user_id,
                ]);

                return;
            }

            // 6. Send the personalized digest notification.
            $notification = $delivery->category === NotificationDigestDelivery::CATEGORY_EPS_SURPRISE
                ? new EpsSurpriseDigestNotification($digestPayload)
                : new SetupScoringDigestNotification($digestPayload);

            $user->notify($notification);

            $delivery->update([
                'status' => NotificationDigestDelivery::STATUS_SENT,
                'sent_at' => now(),
                'summary_counts' => $digestPayload['summary_counts'] ?? [],
                'last_error' => null,
                'claimed_at' => null,
            ]);

            Log::info('digest.delivery_sent', [
                'delivery_id' => $delivery->id,
                'scan_run_id' => $delivery->scan_run_id,
                'category' => $delivery->category,
                'user_id' => $delivery->user_id,
                'attempts' => $delivery->attempts,
                'summary_counts' => $digestPayload['summary_counts'] ?? [],
            ]);
        } catch (Throwable $e) {
            $delivery->update([
                'status' => NotificationDigestDelivery::STATUS_FAILED,
                'last_error' => $e->getMessage(),
                'claimed_at' => null,
            ]);

            Log::error('digest.delivery_failed', [
                'delivery_id' => $delivery->id,
                'scan_run_id' => $delivery->scan_run_id,
                'category' => $delivery->category,
                'user_id' => $delivery->user_id,
                'attempts' => $delivery->attempts,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
