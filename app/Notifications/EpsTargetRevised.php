<?php

namespace App\Notifications;

use App\Models\EpsRevisionAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when the EPS Revision scanner detects that the latest analyst
 * consensus EPS estimate for the next quarter has moved beyond the
 * configured positive/negative threshold versus the previously stored
 * estimate.
 */
class EpsTargetRevised extends Notification
{
    use Queueable;

    public function __construct(public EpsRevisionAlert $alert) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('mail.default') && config('mail.from.address')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function isNegative(): bool
    {
        return $this->alert->direction === EpsRevisionAlert::DIRECTION_NEGATIVE;
    }

    public function label(): string
    {
        return $this->isNegative() ? 'EPS Target Cut' : 'EPS Target Raised';
    }

    public function emoji(): string
    {
        return $this->isNegative() ? '📉' : '📈';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->emoji().' '.$this->label().': '.$this->alert->symbol)
            ->line($this->bodyText())
            ->action('View in DuoAsset', route('watchlists.eps-revisions'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'symbol' => $this->alert->symbol,
            'company_name' => $this->alert->company_name,
            'exchange' => $this->alert->exchange,
            'next_quarter_end_date' => optional($this->alert->next_quarter_end_date)->toDateString(),
            'previous_estimate' => $this->alert->previous_estimate,
            'latest_estimate' => $this->alert->latest_estimate,
            'revision_percent' => $this->alert->revision_percent,
            'direction' => $this->alert->direction,
            'label' => $this->label(),
            'market_cap' => $this->alert->market_cap,
            'eps_revision_alert_id' => $this->alert->id,
            'message' => $this->bodyText(),
        ];
    }

    public function bodyText(): string
    {
        $mcap = $this->alert->market_cap ? number_format((int) $this->alert->market_cap) : 'N/A';
        $pct = $this->signedPercent((float) $this->alert->revision_percent);
        $period = optional($this->alert->next_quarter_end_date)->toDateString() ?? 'next quarter';

        return implode("\n", [
            $this->emoji().' '.$this->label(),
            '',
            $this->alert->symbol.' - '.($this->alert->company_name ?? '—'),
            'Exchange: '.($this->alert->exchange ?? '—'),
            'Market Cap: '.$mcap,
            'Period: '.$period,
            '',
            'Previous Estimate: '.$this->alert->previous_estimate,
            'Latest Estimate: '.$this->alert->latest_estimate,
            'Revision: '.$pct,
        ]);
    }

    protected function signedPercent(float $v): string
    {
        $sign = $v >= 0 ? '+' : '';

        return $sign.number_format($v, 2).'%';
    }
}
