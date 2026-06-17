<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Self-service "forgot password" link. Distinct from PasswordResetNotification
 * (which carries an admin-issued temporary password). This one carries a
 * one-time link the user follows to choose their own new password.
 */
class PasswordResetLinkNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset Your Ogami ERP Password')
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('We received a request to reset the password for your Ogami ERP account.')
            ->action('Reset Password', $this->resetUrl)
            ->line('This link will expire in 60 minutes and can be used only once.')
            ->line('If you did not request a password reset, no action is required — your password will stay the same.')
            ->salutation('— Ogami IT Department');
    }
}
