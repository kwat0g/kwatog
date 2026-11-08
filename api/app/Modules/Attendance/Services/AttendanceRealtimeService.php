<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\OutboxService;
use App\Modules\Attendance\Events\AttendanceChanged;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces one attendance row's refresh signal within each transaction. */
final class AttendanceRealtimeService
{
    public function stage(Attendance $attendance, string $action): void
    {
        $workOrderHashIds = WorkOrder::query()
            ->whereIn('status', [
                WorkOrderStatus::Confirmed->value,
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])
            ->whereHas('crews', fn ($crew) => $crew
                ->whereNull('ended_at')
                ->whereHas('members', fn ($member) => $member->where('employee_id', $attendance->employee_id)))
            ->get(['id'])
            ->map(fn (WorkOrder $workOrder): string => (string) $workOrder->hash_id)
            ->unique()
            ->values()
            ->all();
        $employeeUser = User::query()
            ->where('employee_id', $attendance->employee_id)
            ->first(['id']);
        $actor = Auth::user();

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new AttendanceChanged(
                attendanceHashId: (string) $attendance->hash_id,
                action: $action,
                version: $attendance->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
                workOrderHashIds: $workOrderHashIds,
                employeeUserHashId: $employeeUser === null ? null : (string) $employeeUser->hash_id,
                actorId: $actor instanceof User ? (string) $actor->hash_id : null,
            ),
            "attendance-refresh:{$attendance->getKey()}:{$transactionId}",
        );
    }
}
