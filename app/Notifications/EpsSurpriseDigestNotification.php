<?php

namespace App\Notifications;

use App\Models\NotificationDigestDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EpsSurpriseDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $digest
     */
    public function __construct(public array $digest) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('mail.default')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $viewData = $this->buildViewData($notifiable);
        $counts = $this->digest['summary_counts'] ?? [];
        $watchlistHits = (int) ($counts['watchlist_surprises'] ?? 0) + (int) ($counts['watchlist_misses'] ?? 0);
        $marketHits = (int) ($counts['market_surprises_total'] ?? 0) + (int) ($counts['market_misses_total'] ?? 0);

        $subject = $watchlistHits > 0
            ? sprintf('DuoAsset — EPS Surprise Digest (%d Watchlist, %d Market Events)', $watchlistHits, $marketHits)
            : sprintf('DuoAsset — EPS Surprise Digest (%d Market Events)', $marketHits);

        return (new MailMessage)
            ->subject($subject)
            ->view([
                'html' => 'emails.digests.eps-surprise-html',
                'text' => 'emails.digests.eps-surprise-text',
            ], $viewData);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'eps_surprise_digest',
            'category' => NotificationDigestDelivery::CATEGORY_EPS_SURPRISE,
            'scan_run_id' => $this->digest['scan_run_id'] ?? null,
            'completed_at' => $this->digest['completed_at'] ?? null,
            'summary_counts' => $this->digest['summary_counts'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildViewData(object $notifiable): array
    {
        $timezone = method_exists($notifiable, 'timezone') ? $notifiable->timezone() : 'America/New_York';
        $completedAtRaw = $this->digest['completed_at'] ?? now()->toIso8601String();
        $completedAtFormatted = CarbonImmutable::parse($completedAtRaw)
            ->setTimezone($timezone)
            ->format('Y-m-d H:i T');

        return [
            'digest' => $this->digest,
            'completedAt' => $completedAtFormatted,
            'dashboardUrl' => url('/watchlist/earnings-surprises'),
            'managePreferencesUrl' => route('settings.profile'),
            'unsubscribeCategoryUrl' => route('notifications.unsubscribe.confirm', [
                'category' => NotificationDigestDelivery::CATEGORY_EPS_SURPRISE,
            ]),
            'unsubscribeBothUrl' => route('notifications.unsubscribe.confirm', [
                'category' => 'both',
            ]),
        ];
    }
}
