<?php

namespace App\Notifications;

use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EarningsSurpriseDetected extends Notification
{
    use Queueable;

    public function __construct(
        public EarningsEvent $event,
        public EarningsAlert $alert,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('mail.default') && config('mail.from.address')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('watchlists.earnings-surprises');

        return (new MailMessage)
            ->subject('🚨 EPS Surprise Alert: '.$this->event->symbol)
            ->line($this->bodyText())
            ->action('View in DuoAsset', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'symbol' => $this->event->symbol,
            'company_name' => $this->event->company_name,
            'exchange' => $this->event->exchange,
            'report_date' => optional($this->event->report_date)->toDateString(),
            'eps_estimated' => $this->event->eps_estimated,
            'eps_actual' => $this->event->eps_actual,
            'eps_surprise_percent' => $this->event->eps_surprise_percent,
            'revenue_surprise_percent' => $this->event->revenue_surprise_percent,
            'relative_volume' => $this->event->relative_volume,
            'market_cap' => $this->event->market_cap,
            'score' => $this->alert->score,
            'earnings_event_id' => $this->event->id,
            'earnings_alert_id' => $this->alert->id,
            'message' => $this->bodyText(),
        ];
    }

    public function bodyText(): string
    {
        $mcap = $this->event->market_cap ? number_format((int) $this->event->market_cap) : 'N/A';
        $revPct = $this->event->revenue_surprise_percent !== null
            ? number_format((float) $this->event->revenue_surprise_percent, 2).'%'
            : 'N/A';
        $relVol = $this->event->relative_volume !== null
            ? number_format((float) $this->event->relative_volume, 2)
            : 'N/A';
        $epsPct = $this->event->eps_surprise_percent !== null
            ? '+'.number_format((float) $this->event->eps_surprise_percent, 2).'%'
            : 'N/A';

        return implode("\n", [
            '🚨 EPS Surprise Alert',
            '',
            $this->event->symbol.' - '.($this->event->company_name ?? '—'),
            'Exchange: '.($this->event->exchange ?? '—'),
            'Market Cap: '.$mcap,
            '',
            'EPS Estimate: '.($this->event->eps_estimated ?? 'N/A'),
            'EPS Actual: '.($this->event->eps_actual ?? 'N/A'),
            'EPS Surprise: '.$epsPct,
            '',
            'Revenue Surprise: '.$revPct,
            'Relative Volume: '.$relVol,
            'Score: '.$this->alert->score,
        ]);
    }
}
