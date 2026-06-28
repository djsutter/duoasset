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
        $alert = EpsRevisionAlert::find($this->alertId);
        if (! $alert) {
            return;
        }

        if ($alert->sent_at) {
            return;
        }

        $notification = new EpsTargetRevised($alert);

        $alert->message = $notification->bodyText();

        // Only users who opted in to EPS revision notifications; dedupe
        // per (user, alert) via earnings_notification_deliveries.
        $alertType = EarningsNotificationDelivery::TYPE_REVISION;
        $alertId = $alert->id;

        User::query()
            ->where('notify_eps_revisions', true)
            ->whereDoesntHave('earningsDeliveries', function ($q) use ($alertType, $alertId) {
                $q->where('alert_type', $alertType)->where('alert_id', $alertId);
            })
            ->chunkById(100, function ($users) use ($notification, $alertType, $alertId) {
                foreach ($users as $user) {
                    try {
                        $user->notify($notification);
                        EarningsNotificationDelivery::create([
                            'user_id' => $user->id,
                            'alert_type' => $alertType,
                            'alert_id' => $alertId,
                            'sent_at' => now(),
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('revision.notify_failed', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $extra = config('market_data.earnings_scanner.notification_email');
        if ($extra) {
            Notification::route('mail', $extra)->notify($notification);
        }

        $alert->status = 'sent';
        $alert->sent_at = now();
        $alert->save();
    }
}
