<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use App\Common\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
{
    use Queueable;

    /**
     * PROTECTED and NOT readonly — `private readonly` here kills the queued HR
     * subclass (EmployeePasswordResetNotification) exactly as it did the welcome
     * email: SerializesModels rewrites a private property under the CONCRETE
     * class name (so the subclass cannot hydrate it) and a readonly property
     * cannot be initialized from the child's scope. Either way no employee
     * received their reset password.
     *
     * @see \App\Modules\Auth\Notifications\WelcomeNotification
     * @see \Tests\Feature\HR\EmployeeWelcomeNotificationTest
     */
    public function __construct(protected string $tempPassword) {}

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
        $company = app(SettingsService::class)->requiredString('company.legal_name');

        return (new MailMessage)
            ->subject("Your {$company} ERP Password Has Been Reset")
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line("An administrator has reset your {$company} ERP password.")
            ->line('Login URL: '.$appUrl)
            ->line('Temporary Password: '.$this->tempPassword)
            ->line('You will be required to change your password on next login.')
            ->salutation("— {$company} HR Department");
    }
}
