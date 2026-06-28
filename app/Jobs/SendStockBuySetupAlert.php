<?php

namespace App\Jobs;

use App\Models\EarningsNotificationDelivery;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Notifications\StockBuySetupDetected;
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
        $alert = StockBuySetupAlert::find($this->alertId);
        if (! $alert || $alert->sent_at) {
            return;
        }

        $notification = new StockBuySetupDetected($alert);

        $alertType = EarningsNotificationDelivery::TYPE_BUY_SETUP;
        $alertId = $alert->id;

        User::query()
            ->where('notify_stock_buy_setup', true)
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
                        Log::warning('buy_setup.notify_failed', [
                            'user_id' => $user->id,
                            'alert_id' => $alertId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $extra = config('market_data.buy_setup_scanner.notification_email');
        if ($extra) {
            Notification::route('mail', $extra)->notify($notification);
        }

        $alert->status = 'sent';
        $alert->sent_at = now();
        $alert->save();
    }
}
