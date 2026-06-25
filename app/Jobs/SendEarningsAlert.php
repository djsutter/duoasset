<?php

namespace App\Jobs;

use App\Models\EarningsAlert;
use App\Models\User;
use App\Notifications\EarningsSurpriseDetected;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        // Notify all users (DuoAsset is a single-user / small-team app).
        User::query()->chunkById(100, function ($users) use ($notification) {
            Notification::send($users, $notification);
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
