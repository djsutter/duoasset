<?php

namespace App\Notifications;

use App\Models\StockBuySetupAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when the Stock Buy Setup scanner detects a qualifying spike +
 * tight consolidation base with a heartbeat_score above the configured
 * minimum.
 */
class StockBuySetupDetected extends Notification
{
    use Queueable;

    public function __construct(public StockBuySetupAlert $alert) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('mail.default') && config('mail.from.address')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function label(): string
    {
        return 'Stock Buy Setup';
    }

    public function emoji(): string
    {
        return '🟢';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->emoji().' '.$this->label().': '.$this->alert->symbol)
            ->line($this->bodyText())
            ->action('View in DuoAsset', route('watchlists.stock-buy-setups'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'symbol' => $this->alert->symbol,
            'company_name' => $this->alert->company_name,
            'exchange' => $this->alert->exchange,
            'market_cap' => $this->alert->market_cap,
            'market_cap_category' => $this->alert->market_cap_category,
            'spike_date' => optional($this->alert->spike_date)->toDateString(),
            'heartbeat_score' => $this->alert->heartbeat_score,
            'range_compression_pct' => $this->alert->range_compression_pct,
            'atr_contraction_ratio' => $this->alert->atr_contraction_ratio,
            'distance_to_breakout_pct' => $this->alert->distance_to_breakout_pct,
            'relative_strength_score' => $this->alert->relative_strength_score,
            'stock_buy_setup_alert_id' => $this->alert->id,
            'message' => $this->bodyText(),
            'label' => $this->label(),
        ];
    }

    public function bodyText(): string
    {
        $mcap = $this->alert->market_cap ? number_format((int) $this->alert->market_cap) : 'N/A';

        return implode("\n", [
            $this->emoji().' '.$this->label().' ('.$this->alert->heartbeat_score.'/100)',
            '',
            $this->alert->symbol.' - '.($this->alert->company_name ?? '—'),
            'Exchange: '.($this->alert->exchange ?? '—'),
            'Market Cap: '.$mcap,
            'Spike Date: '.optional($this->alert->spike_date)->toDateString(),
            'Base Duration: '.$this->alert->base_duration_days.' days',
            'Range Compression: '.$this->alert->range_compression_pct.'%',
            'ATR Contraction Ratio: '.$this->alert->atr_contraction_ratio,
            'Distance to Breakout: '.$this->alert->distance_to_breakout_pct.'%',
            '',
            'Reason: '.$this->alert->reason_summary,
        ]);
    }
}
