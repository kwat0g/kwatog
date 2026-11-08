<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** The reviewed crew, schedule, or worker availability changed before assignment. */
final class WorkOrderAssignmentConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'work_order_assignment_conflict';
    }

    public function errorKey(): string
    {
        return 'assignment';
    }
}
