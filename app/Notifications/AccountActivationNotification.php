<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountActivationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
    ) {}

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
        $activationUrl = route('activate-account', ['token' => $this->token]);

        return (new MailMessage)
            ->subject('Activate Your DuoAsset Account')
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line('Thank you for registering with DuoAsset! Please click the button below to verify your email address and activate your account:')
            ->action('Activate Account', $activationUrl)
            ->line('This activation link is unique to your registration and can only be used once.')
            ->line('If you did not create an account, no further action is required.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'token_hash' => hash('sha256', $this->token),
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
