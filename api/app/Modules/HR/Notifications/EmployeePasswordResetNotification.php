<?php

declare(strict_types=1);

namespace App\Modules\HR\Notifications;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued, post-commit HR password-reset notification. */
class EmployeePasswordResetNotification extends PasswordResetNotification implements ShouldQueue
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
            'Employee password reset email',
            'The password reset link could not be delivered. Ask the employee to request a new link or use an approved support process.',
            ['reason' => 'The queued password reset notification failed.'],
        );
    }
}
