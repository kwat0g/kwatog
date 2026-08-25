<?php

declare(strict_types=1);

namespace App\Modules\HR\Notifications;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Auth\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * HR account-provisioning welcome message.
 *
 * The Auth notification owns the mail wording; this module-owned wrapper adds
 * the queue and post-commit delivery boundary needed by HR provisioning.
 */
class EmployeeWelcomeNotification extends WelcomeNotification implements ShouldQueue
{
    public function __construct(string $tempPassword)
    {
        parent::__construct($tempPassword);
        $this->afterCommit();
    }

    public function failed(\Throwable $exception): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyPermission(
            'hr.employees.view',
            'Employee welcome email',
            'The welcome email could not be delivered. Provide the temporary credentials through an approved channel.',
            ['reason' => 'The queued welcome notification failed.'],
        );
    }
}
