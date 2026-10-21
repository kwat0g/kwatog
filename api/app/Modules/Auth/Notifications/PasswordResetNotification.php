<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use App\Common\Services\EmailBrandingService;
use App\Modules\Auth\Services\PasswordResetService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Tests\Feature\HR\EmployeeWelcomeNotificationTest;

class PasswordResetNotification extends Notification
{
    use Queueable;

    protected ?string $resetUrl = null;

    protected int $expiryMinutes = 0;

    // Keep the serialized property name so queued legacy password jobs can be
    // converted into a fresh one-time reset link without mailing the password.
    protected string $tempPassword = '';

    public $tries = 3;

    public $backoff = [60, 300, 900];

    /**
     * PROTECTED and NOT readonly — `private readonly` here kills the queued HR
     * subclass (EmployeePasswordResetNotification) exactly as it did the welcome
     * email: SerializesModels rewrites a private property under the CONCRETE
     * class name (so the subclass cannot hydrate it) and a readonly property
     * cannot be initialized from the child's scope. Either way no employee
     * received their reset password.
     *
     * @see WelcomeNotification
     * @see EmployeeWelcomeNotificationTest
     */
    public function __construct(string $resetUrl, int $expiryMinutes)
    {
        $this->resetUrl = $resetUrl;
        $this->expiryMinutes = $expiryMinutes;
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
        if ($this->resetUrl === null) {
            $reset = app(PasswordResetService::class)->createResetLink($notifiable);
            $this->resetUrl = $reset['url'];
            $this->expiryMinutes = $reset['expiry_minutes'];
        }
        $brandName = app(EmailBrandingService::class)->data()['name'];

        return (new MailMessage)
            ->subject('['.$brandName.'] Your OGAMI ERP password was reset')
            ->markdown('emails.account-access-link', [
                'preheader' => 'Use a secure link to set a new OGAMI ERP password.',
                'title' => 'Your password was reset',
                'greeting' => 'Hello '.($notifiable->name ?? 'there'),
                'message' => 'An administrator reset your password. Use the secure link to choose a new password before signing in.',
                'actionText' => 'Set a new password',
                'actionUrl' => $this->resetUrl,
                'expiryMinutes' => $this->expiryMinutes,
                'footerNote' => 'If you did not expect this change, contact your company administrator immediately.',
            ]);
    }
}
