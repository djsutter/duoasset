<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorAuthenticationCode extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $expiresInMinutes = 7,
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
        return (new MailMessage)
            ->subject('Your DuoAsset Two-Factor Authentication Code')
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line('Your two-factor authentication security code is:')
            ->line('**' . $this->code . '**')
            ->line("This verification code will expire in {$this->expiresInMinutes} minutes.")
            ->line('If you did not request this login attempt, please secure your account immediately.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'code' => $this->code,
            'expires_in_minutes' => $this->expiresInMinutes,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
