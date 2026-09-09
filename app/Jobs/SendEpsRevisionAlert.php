<?php

namespace App\Jobs;

use App\Models\EarningsNotificationDelivery;
use App\Models\EpsRevisionAlert;
use App\Models\User;
use App\Notifications\EpsTargetRevised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Deliver an EpsTargetRevised notification for a stored EpsRevisionAlert.
 * Idempotent on `sent_at`.
 */
class SendEpsRevisionAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $alertId) {}

    public function handle(): void
    {
        Log::info('revision.notification_job_started', [
            'alert_id' => $this->alertId,
            'mailer' => config('mail.default'),
            'from_address' => config('mail.from.address'),
        ]);

        $alert = EpsRevisionAlert::find($this->alertId);
        if (! $alert) {
            Log::warning('revision.notification_alert_not_found', ['alert_id' => $this->alertId]);

            return;
        }

        if ($alert->sent_at) {
            Log::info('revision.notification_alert_already_sent', [
                'alert_id' => $this->alertId,
                'sent_at' => $alert->sent_at,
            ]);

            return;
        }

        $notification = new EpsTargetRevised($alert);

        $alert->message = $notification->bodyText();

        // Only users who opted in to EPS revision notifications; dedupe
        // per (user, alert) via earnings_notification_deliveries.
        $alertType = EarningsNotificationDelivery::TYPE_REVISION;
        $alertId = $alert->id;

        $userCount = 0;
        User::query()
            ->where('notify_eps_revisions', true)
            ->whereDoesntHave('earningsDeliveries', function ($q) use ($alertType, $alertId) {
                $q->where('alert_type', $alertType)->where('alert_id', $alertId);
            })
            ->chunkById(100, function ($users) use ($notification, $alertType, $alertId, &$userCount) {
                foreach ($users as $user) {
                    $userCount++;
                    try {
                        Log::info('revision.notifying_user', [
                            'user_id' => $user->id,
                            'email' => $user->email,
                            'alert_id' => $alertId,
                        ]);
                        $user->notify($notification);
                        EarningsNotificationDelivery::create([
                            'user_id' => $user->id,
                            'alert_type' => $alertType,
                            'alert_id' => $alertId,
                            'sent_at' => now(),
                        ]);
                        Log::info('revision.user_notified', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('revision.notify_failed', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            });

        Log::info('revision.user_notifications_completed', [
            'alert_id' => $alertId,
            'users_notified_count' => $userCount,
        ]);

        $extra = config('market_data.earnings_scanner.notification_email');
        if ($extra) {
            try {
                Log::info('revision.notifying_extra_email', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                    'mailer' => config('mail.default'),
                    'from_address' => config('mail.from.address'),
                ]);
                Notification::route('mail', $extra)->notify($notification);
                Log::info('revision.extra_email_notified', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                ]);
            } catch (\Throwable $e) {
                Log::error('revision.extra_email_notify_failed', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } else {
            Log::info('revision.no_extra_email_configured', ['alert_id' => $alertId]);
        }

        $alert->status = 'sent';
        $alert->sent_at = now();
        $alert->save();
    }
}
