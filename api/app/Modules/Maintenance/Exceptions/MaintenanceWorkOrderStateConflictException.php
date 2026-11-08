<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A maintenance work-order action was submitted against an outdated state. */
final class MaintenanceWorkOrderStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'maintenance_work_order_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
