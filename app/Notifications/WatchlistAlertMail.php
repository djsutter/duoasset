<?php

namespace App\Notifications;

use App\Models\Stock;
use App\Models\WatchlistAlertEvent;
use App\Models\WatchlistAlertRule;
use App\Models\WatchlistItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email notification for a triggered watchlist alert.
 *
 * Keeps things boring: a single Mail channel, no queueing by default so it
 * also runs synchronously under the `market-watch:update-quotes` command,
 * and uses translation strings via lang/en/alerts.php.
 */
class WatchlistAlertMail extends Notification
{
    use Queueable;

    public function __construct(
        public readonly WatchlistAlertEvent $event,
        public readonly WatchlistAlertRule $rule,
        public readonly WatchlistItem $item,
        public readonly Stock $stock,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = __('alerts.mail.subject', [
            'severity' => strtoupper($this->event->severity->value),
            'symbol' => $this->stock->symbol,
            'rule' => $this->rule->type->label(),
        ]);

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting(__('alerts.mail.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('alerts.mail.intro', [
                'symbol' => $this->stock->symbol,
                'rule' => $this->rule->type->label(),
            ]));

        if ($this->stock->last_price) {
            $mail->line(__('alerts.mail.price_line', [
                'price' => $this->stock->last_price->format(),
            ]));
        }

        if ($this->event->message) {
            $mail->line(__('alerts.mail.message_line', [
                'message' => $this->event->message,
            ]));
        }

        $mail->action(
            __('alerts.mail.view_action'),
            url(route('watchlists.show', $this->item->watchlist_id))
        );

        return $mail;
    }

    /**
     * For testability — array representation of the notification payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->id,
            'rule_id' => $this->rule->id,
            'item_id' => $this->item->id,
            'stock_id' => $this->stock->id,
            'severity' => $this->event->severity->value,
        ];
    }
}
