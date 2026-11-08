<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Observers;

use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Services\ShiftAssignmentRealtimeService;

/** Captures assignment writes from attendance, HR, onboarding, and imports. */
final class ShiftAssignmentRealtimeObserver
{
    public function created(EmployeeShiftAssignment $assignment): void
    {
        $this->stage($assignment, 'created');
    }

    public function updated(EmployeeShiftAssignment $assignment): void
    {
        $this->stage($assignment, 'updated');
    }

    public function deleted(EmployeeShiftAssignment $assignment): void
    {
        $this->stage($assignment, 'deleted');
    }

    private function stage(EmployeeShiftAssignment $assignment, string $action): void
    {
        app(ShiftAssignmentRealtimeService::class)->stage($assignment, $action);
    }
}
