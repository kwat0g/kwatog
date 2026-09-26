<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use App\Common\Services\SettingsService;
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

    /**
     * PROTECTED and NOT readonly, on purpose — both modifiers break the queue.
     *
     * Illuminate\Notifications\Notification uses SerializesModels:
     *   - `private` is rewritten as "\0{get_class($this)}\0{$name}" using the
     *     CONCRETE class name, so a subclass (EmployeeWelcomeNotification)
     *     cannot hydrate the parent's property. The job died on unserialize.
     *   - `readonly` cannot be re-initialized from the child's scope, and
     *     SerializesModels' __unserialize() writes from the child — it throws
     *     "Cannot initialize readonly property … from scope <Child>".
     *
     * Either way no new hire ever received their temporary password.
     *
     * @see \Illuminate\Queue\SerializesModels::__serialize()
     * @see \Illuminate\Queue\SerializesModels::__unserialize()
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
        $email = $notifiable->email ?? '';
        $company = app(SettingsService::class)->requiredString('company.legal_name');

        return (new MailMessage)
            ->subject("Welcome to {$company} ERP — Your Account is Ready")
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line("Your {$company} ERP account has been created.")
            ->line('Login URL: '.$appUrl)
            ->line('Email: '.$email)
            ->line('Temporary Password: '.$this->tempPassword)
            ->line('You will be required to change your password on first login.')
            ->salutation("— {$company} HR Department");
    }
}
