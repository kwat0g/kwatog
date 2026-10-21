<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

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
        $brandName = app(EmailBrandingService::class)->data()['name'];

        return (new MailMessage)
            ->subject('['.$brandName.'] Reset your OGAMI ERP password')
            ->markdown('emails.account-access-link', [
                'preheader' => 'Use a secure link to reset your OGAMI ERP password.',
                'title' => 'Reset your password',
                'greeting' => 'Hello '.($notifiable->name ?? 'there'),
                'message' => 'We received a request to reset your OGAMI ERP password. Use the secure link below to choose a new password.',
                'actionText' => 'Reset password',
                'actionUrl' => $this->resetUrl,
                'expiryMinutes' => $this->expiryMinutes,
                'footerNote' => 'If you did not request a reset, no action is required. Your current password will stay the same.',
            ]);
    }

    public function failed(Throwable $exception): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyPermission(
            'admin.users.manage',
            'Password reset email',
            'A requested password reset link could not be delivered. Ask the user to request a new link.',
            ['reason' => 'The queued reset notification failed.'],
        );
    }
}
