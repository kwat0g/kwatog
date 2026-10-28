<?php

declare(strict_types=1);

namespace App\Modules\Auth\Notifications;

use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class AdminPasswordResetNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    protected string $temporaryPassword;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(string $temporaryPassword)
    {
        $this->temporaryPassword = $temporaryPassword;
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
            ->subject('['.$brandName.'] Your temporary OGAMI ERP password')
            ->greeting('Hello '.($notifiable->name ?? 'there'))
            ->line('An administrator reset your password.')
            ->line('Temporary password: `'.$this->temporaryPassword.'`')
            ->line('You must change this password when you sign in.')
            ->line('If you did not expect this reset, contact your company administrator.');
    }

    public function failed(Throwable $exception): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyPermission(
            'admin.users.manage',
            'Temporary password email',
            'An administrator-issued temporary password could not be delivered. Verify the account email and assist the user.',
            ['reason' => 'The queued temporary password notification failed.'],
        );
    }
}
