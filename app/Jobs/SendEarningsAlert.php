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
        $alert = EarningsAlert::with('earningsEvent')->find($this->alertId);
        if (! $alert || ! $alert->earningsEvent) {
            return;
        }

        if ($alert->sent_at) {
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

        User::query()
            ->where('notify_eps_earnings', true)
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
                        // Don't let one bad recipient kill the rest. The
                        // missing delivery row means a later run will
                        // retry this user only.
                        Log::warning('earnings.notify_failed', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // Optional plain email recipient configured via env.
        $extra = config('market_data.earnings_scanner.notification_email');
        if ($extra) {
            Notification::route('mail', $extra)->notify($notification);
        }

        $alert->status = 'sent';
        $alert->sent_at = now();
        $alert->save();
    }
}
