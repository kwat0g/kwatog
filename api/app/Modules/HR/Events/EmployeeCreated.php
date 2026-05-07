<?php

declare(strict_types=1);

namespace App\Modules\HR\Events;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C3. Fired AFTER EmployeeService::create() commits.
 * Drives InitializeLeaveBalances and any future onboarding-side
 * listeners that need to react to a new hire.
 */
class EmployeeCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Employee $employee) {}
}
