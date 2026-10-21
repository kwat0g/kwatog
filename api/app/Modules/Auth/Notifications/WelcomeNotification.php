<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use App\Common\Services\EmailBrandingService;
use App\Modules\Auth\Services\PasswordResetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome email sent when an employee's system account is provisioned.
 * Carries a one-time setup link, never a password.
 */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected ?string $resetUrl = null;

    protected int $expiryMinutes = 0;

    // Keep the serialized property name so already-queued legacy jobs hydrate;
    // their password is ignored and replaced with a fresh setup link at send time.
    protected string $tempPassword = '';

    public $tries = 3;

    public $backoff = [60, 300, 900];

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
     * @see SerializesModels::__serialize()
     * @see SerializesModels::__unserialize()
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
            $setup = app(PasswordResetService::class)->createResetLink($notifiable);
            $this->resetUrl = $setup['url'];
            $this->expiryMinutes = $setup['expiry_minutes'];
        }
        $brandName = app(EmailBrandingService::class)->data()['name'];

        return (new MailMessage)
            ->subject('['.$brandName.'] Your OGAMI ERP account is ready')
            ->markdown('emails.account-access-link', [
                'preheader' => 'Set a password to activate your OGAMI ERP account.',
                'title' => 'Your account is ready',
                'greeting' => 'Hello '.($notifiable->name ?? 'there'),
                'message' => 'Your OGAMI ERP account has been created. Use the secure link to choose your password and sign in.',
                'actionText' => 'Set your password',
                'actionUrl' => $this->resetUrl,
                'expiryMinutes' => $this->expiryMinutes,
                'footerNote' => 'If you were not expecting this account, contact your company administrator.',
            ]);
    }
}
