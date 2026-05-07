<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
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

        return (new MailMessage)
            ->subject('Your Ogami ERP Password Has Been Reset')
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('An administrator has reset your Ogami ERP password.')
            ->line('Login URL: '.$appUrl)
            ->line('Temporary Password: '.$this->tempPassword)
            ->line('You will be required to change your password on next login.')
            ->salutation('— Ogami HR Department');
    }
}
