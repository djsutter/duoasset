<?php

namespace App\Jobs;

use App\Models\EarningsNotificationDelivery;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Notifications\StockBuySetupDetected;
use App\Services\Stocks\BuySetupConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Deliver a StockBuySetupDetected notification for a stored
 * StockBuySetupAlert. Mirrors SendEpsRevisionAlert: idempotent via
 * earnings_notification_deliveries with alert_type='buy_setup'.
 */
class SendStockBuySetupAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $alertId) {}

    public function handle(): void
    {
        Log::info('buy_setup.notification_job_started', [
            'alert_id' => $this->alertId,
            'mailer' => config('mail.default'),
            'from_address' => config('mail.from.address'),
        ]);

        $alert = StockBuySetupAlert::find($this->alertId);
        if (! $alert) {
            Log::warning('buy_setup.notification_alert_not_found', ['alert_id' => $this->alertId]);

            return;
        }

        if ($alert->sent_at) {
            Log::info('buy_setup.notification_alert_already_sent', [
                'alert_id' => $this->alertId,
                'sent_at' => $alert->sent_at,
            ]);

            return;
        }

        $notification = new StockBuySetupDetected($alert);

        $alertType = EarningsNotificationDelivery::TYPE_BUY_SETUP;
        $alertId = $alert->id;

        $userCount = 0;
        User::query()
            ->where('notify_stock_buy_setup', true)
            ->whereDoesntHave('earningsDeliveries', function ($q) use ($alertType, $alertId) {
                $q->where('alert_type', $alertType)->where('alert_id', $alertId);
            })
            ->chunkById(100, function ($users) use ($notification, $alertType, $alertId, &$userCount) {
                foreach ($users as $user) {
                    $userCount++;
                    try {
                        Log::info('buy_setup.notifying_user', [
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
                        Log::info('buy_setup.user_notified', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('buy_setup.notify_failed', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            });

        Log::info('buy_setup.user_notifications_completed', [
            'alert_id' => $alertId,
            'users_notified_count' => $userCount,
        ]);

        $extra = app(BuySetupConfigService::class)->getNotificationEmail();
        if ($extra) {
            try {
                Log::info('buy_setup.notifying_extra_email', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                    'mailer' => config('mail.default'),
                    'from_address' => config('mail.from.address'),
                ]);
                Notification::route('mail', $extra)->notify($notification);
                Log::info('buy_setup.extra_email_notified', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                ]);
            } catch (\Throwable $e) {
                Log::error('buy_setup.extra_email_notify_failed', [
                    'alert_id' => $alertId,
                    'email' => $extra,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } else {
            Log::info('buy_setup.no_extra_email_configured', ['alert_id' => $alertId]);
        }

        $alert->status = 'sent';
        $alert->sent_at = now();
        $alert->save();
    }
}
