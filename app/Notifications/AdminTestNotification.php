<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class AdminTestNotification extends Notification
{
    use Queueable;

    public Carbon $sentAt;

    public string $sender;

    public function __construct(?Carbon $sentAt = null, ?string $sender = null)
    {
        $this->sentAt = $sentAt ?? now();
        $this->sender = $sender ?? (config('mail.from.address') ?: 'duoasset@12pro.ca');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $recipientEmail = (method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('mail') : null)
            ?: ($notifiable->email ?? ($notifiable->routes['mail'] ?? 'Unknown'));

        $timestamp = $this->sentAt->toDateTimeString().' ('.$this->sentAt->timezoneName.')';

        return (new MailMessage)
            ->from($this->sender, config('mail.from.name', 'DuoAsset'))
            ->subject('DuoAsset System - Test Email Verification')
            ->greeting('DuoAsset Diagnostic Test Email')
            ->line('This diagnostic verification message confirms that your application outbound mail transport is operating correctly.')
            ->line('Delivery Details:')
            ->line('• Recipient: '.$recipientEmail)
            ->line('• Configured Sender: '.$this->sender)
            ->line('• Generated At: '.$timestamp)
            ->line('If you received this message, the configured mail transport is actively delivering emails.')
            ->action('Open Admin Configuration', route('admin.config'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin_test_email',
            'recipient' => (method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('mail') : null)
                ?: ($notifiable->email ?? null),
            'sender' => $this->sender,
            'sent_at' => $this->sentAt->toIso8601String(),
        ];
    }
}
