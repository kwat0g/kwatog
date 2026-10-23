<?php

declare(strict_types=1);

namespace App\Modules\HR\Notifications;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Auth\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * HR account-provisioning welcome message.
 *
 * The Auth notification owns the mail wording; this wrapper applies the
 * post-commit delivery boundary needed by HR provisioning.
 */
class EmployeeWelcomeNotification extends WelcomeNotification implements ShouldQueue
{
    public function __construct(string $resetUrl, int $expiryMinutes)
    {
        parent::__construct($resetUrl, $expiryMinutes);
        $this->afterCommit();
    }

    public function failed(\Throwable $exception): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyPermission(
            'hr.employees.view',
            'Employee welcome email',
            'The account setup link could not be delivered. Ask the employee to request a password reset or use an approved support process.',
            ['reason' => 'The queued welcome notification failed.'],
        );
    }
}
