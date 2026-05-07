<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email sent when an employee's system account is provisioned.
 * Carries the temporary password (one-time, will be force-changed on login).
 */
class WelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $tempPassword) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $appUrl = config('app.frontend_url', config('app.url'));
        $email = $notifiable->email ?? '';

        return (new MailMessage)
            ->subject('Welcome to Ogami ERP — Your Account is Ready')
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('Your Ogami ERP account has been created.')
            ->line('Login URL: '.$appUrl)
            ->line('Email: '.$email)
            ->line('Temporary Password: '.$this->tempPassword)
            ->line('You will be required to change your password on first login.')
            ->salutation('— Ogami HR Department');
    }
}
