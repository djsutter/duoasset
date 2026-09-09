<?php

namespace App\Jobs;

use App\Models\EarningsAlert;
use App\Models\EarningsNotificationDelivery;
use App\Models\User;
use App\Notifications\EarningsSurpriseDetected;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendEarningsAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $alertId) {}

    public function handle(): void
    {
        Log::info('earnings.notification_job_started', [
            'alert_id' => $this->alertId,
            'mailer' => config('mail.default'),
            'from_address' => config('mail.from.address'),
        ]);

        $alert = EarningsAlert::with('earningsEvent')->find($this->alertId);
        if (! $alert || ! $alert->earningsEvent) {
            Log::warning('earnings.notification_alert_not_found', ['alert_id' => $this->alertId]);

            return;
        }

        if ($alert->sent_at) {
            Log::info('earnings.notification_alert_already_sent', [
                'alert_id' => $this->alertId,
                'sent_at' => $alert->sent_at,
            ]);

            return; // already sent
        }

        $notification = new EarningsSurpriseDetected($alert->earningsEvent, $alert);

        // Persist the rendered message for the UI/history.
        $alert->message = $notification->bodyText();

        // Notify only users who opted in to EPS earnings notifications,
        // and skip any user who has already received THIS alert (dedupe
        // via earnings_notification_deliveries unique index).
        $alertType = EarningsNotificationDelivery::TYPE_EARNINGS;
        $alertId = $alert->id;

        $userCount = 0;
        User::query()
            ->where('notify_eps_earnings', true)
            ->whereDoesntHave('earningsDeliveries', function ($q) use ($alertType, $alertId) {
                $q->where('alert_type', $alertType)->where('alert_id', $alertId);
            })
            ->chunkById(100, function ($users) use ($notification, $alertType, $alertId, &$userCount) {
                foreach ($users as $user) {
                    $userCount++;
                    try {
                        Log::info('earnings.notifying_user', [
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
                        Log::info('earnings.user_notified', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                        ]);
                    } catch (\Throwable $e) {
                        // Don't let one bad recipient kill the rest. The
                        // missing delivery row means a later run will
                        // retry this user only.
                        Log::error('earnings.notify_failed', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            });

        Log::info('earnings.user_notifications_completed', [
            'alert_id' => $alertId,
            'users_notified_count' => $userCount,
        ]);

        // Optional plain email recipient configured via env.
        $extra = config('market_data.earnings_scanner.notification_email');
        if ($extra) {
            try {
                Log::info('earnings.notifying_extra_email', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                    'mailer' => config('mail.default'),
                    'from_address' => config('mail.from.address'),
                ]);
                Notification::route('mail', $extra)->notify($notification);
                Log::info('earnings.extra_email_notified', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                ]);
            } catch (\Throwable $e) {
                Log::error('earnings.extra_email_notify_failed', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } else {
            Log::info('earnings.no_extra_email_configured', ['alert_id' => $alertId]);
        }

        $alert->status = 'sent';
        $alert->sent_at = now();
        $alert->save();
    }
}
