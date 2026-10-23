<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use App\Common\Services\EmailBrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Self-service "forgot password" link. Distinct from PasswordResetNotification
 * (which carries an admin-issued temporary password). This one carries a
 * one-time link the user follows to choose their own new password.
 */
class PasswordResetLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        private readonly string $resetUrl,
        private readonly int $expiryMinutes,
    ) {
        $this->onQueue('mail')->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $company = app(EmailBrandingService::class)->data()['legal_name'];
        return (new MailMessage)
            ->subject("Reset Your {$company} ERP Password")
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line("We received a request to reset the password for your {$company} ERP account.")
            ->action('Reset Password', $this->resetUrl)
            ->line("This link will expire in {$this->expiryMinutes} minutes and can be used only once.")
            ->line('If you did not request a password reset, no action is required — your password will stay the same.')
            ->salutation("— {$company} IT Department");
    }
}
