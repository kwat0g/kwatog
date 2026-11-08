<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\OutboxService;
use App\Modules\Attendance\Events\ShiftAssignmentChanged;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces assignment refresh pings by row and database transaction. */
final class ShiftAssignmentRealtimeService
{
    public function stage(EmployeeShiftAssignment $assignment, string $action): void
    {
        // A previously-created assignment can keep the old employee relation
        // loaded after employee_id changes. Resolve the persisted current owner
        // directly so the event and both readiness audiences are refreshed.
        $employee = Employee::withTrashed()->find($assignment->employee_id);
        if ($employee === null) {
            return;
        }

        $employeeIds = [(int) $employee->getKey()];
        $previousEmployeeId = $assignment->wasChanged('employee_id')
            ? (int) ($assignment->getPrevious()['employee_id'] ?? 0)
            : 0;
        if ($previousEmployeeId > 0 && $previousEmployeeId !== (int) $employee->getKey()) {
            $employeeIds[] = $previousEmployeeId;
        }
        $relatedEmployeeHashIds = Employee::withTrashed()
            ->whereIn('id', $employeeIds)
            ->whereKeyNot($employee->getKey())
            ->get()
            ->map(fn (Employee $relatedEmployee): string => (string) $relatedEmployee->hash_id)
            ->filter()
            ->values()
            ->all();

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new ShiftAssignmentChanged(
                assignmentHashId: (string) $assignment->hash_id,
                employeeHashId: (string) $employee->hash_id,
                action: $action,
                version: $assignment->updated_at?->format('Y-m-d\\TH:i:s.uP')
                    ?? $assignment->created_at?->format('Y-m-d\\TH:i:s.uP')
                    ?? now()->format('Y-m-d\\TH:i:s.uP'),
                actorUserHashId: Auth::user()?->hash_id,
                relatedEmployeeHashIds: $relatedEmployeeHashIds,
            ),
            'shift-assignment-refresh:'.$assignment->getKey().':'.$transactionId,
        );

        app(AssignedWorkOrderRefreshService::class)->stageForEmployees($employeeIds);
    }
}
