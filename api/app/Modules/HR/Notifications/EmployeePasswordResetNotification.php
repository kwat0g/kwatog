<?php

declare(strict_types=1);

namespace App\Modules\HR\Notifications;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued, post-commit HR password-reset notification. */
class EmployeePasswordResetNotification extends PasswordResetNotification implements ShouldQueue
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
            'Employee password reset email',
            'The password reset email could not be delivered. Provide the temporary credentials through an approved channel.',
            ['reason' => 'The queued password reset notification failed.'],
        );
    }
}
