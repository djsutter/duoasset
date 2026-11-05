<?php

namespace App\Notifications;

use App\Models\NotificationDigestDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SetupScoringDigestNotification extends Notification
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
        $watchlistChanges = (int) ($counts['watchlist_improvements'] ?? 0)
            + (int) ($counts['watchlist_drops'] ?? 0)
            + (int) ($counts['watchlist_newly_scored'] ?? 0);

        $subject = $watchlistChanges > 0
            ? sprintf('DuoAsset — Setup Scoring Digest (%d Watchlist Updates)', $watchlistChanges)
            : 'DuoAsset — Setup Scoring Digest';

        return (new MailMessage)
            ->subject($subject)
            ->view([
                'html' => 'emails.digests.setup-scoring-html',
                'text' => 'emails.digests.setup-scoring-text',
            ], $viewData);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'setup_scoring_digest',
            'category' => NotificationDigestDelivery::CATEGORY_SETUP_SCORING,
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
            'dashboardUrl' => $this->digest['watchlists_dashboard_url'] ?? url('/watchlist/stock-buy-setups'),
            'managePreferencesUrl' => route('settings.profile'),
            'unsubscribeCategoryUrl' => route('notifications.unsubscribe.confirm', [
                'category' => NotificationDigestDelivery::CATEGORY_SETUP_SCORING,
            ]),
            'unsubscribeBothUrl' => route('notifications.unsubscribe.confirm', [
                'category' => 'both',
            ]),
        ];
    }
}
