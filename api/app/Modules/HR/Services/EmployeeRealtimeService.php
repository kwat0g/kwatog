<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Admin\Services\AdminUserRealtimeService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\EmployeeChanged;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces employee refresh pings per record and database transaction. */
final class EmployeeRealtimeService
{
    public function stage(Employee $employee, string $action, bool $refreshAdminUserDirectory = true): void
    {
        $departmentIds = array_values(array_unique(array_filter([
            (int) $employee->getRawOriginal('department_id'),
            (int) $employee->department_id,
        ])));
        $departments = Department::query()->whereIn('id', $departmentIds)->get(['id']);
        $departmentHashIds = $departments->map(static fn (Department $department): string => (string) $department->hash_id)->values()->all();

        $related = $departments->map(static fn (Department $department): array => [
            'entity_type' => 'department',
            'hash_id' => (string) $department->hash_id,
        ])->all();
        $positionIds = array_values(array_unique(array_filter([
            (int) $employee->getRawOriginal('position_id'),
            (int) $employee->position_id,
        ])));
        $related = array_merge($related, Position::query()->whereIn('id', $positionIds)->get(['id'])
            ->map(static fn (Position $position): array => [
                'entity_type' => 'position',
                'hash_id' => (string) $position->hash_id,
            ])->all());

        $actor = auth()->user();
        $actorId = $actor instanceof User ? (string) $actor->hash_id : null;
        $linkedUser = User::query()->where('employee_id', $employee->getKey())->first(['id', 'updated_at']);
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new EmployeeChanged(
            hashId: (string) $employee->hash_id,
            action: $action,
            version: $employee->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            departmentHashIds: $departmentHashIds,
            userHashId: $linkedUser === null ? null : (string) $linkedUser->hash_id,
            related: $related,
            actorId: $actorId,
        ), "employee-refresh:{$employee->getKey()}:{$transactionId}");

        if ($refreshAdminUserDirectory && $linkedUser !== null) {
            app(AdminUserRealtimeService::class)->stage(
                $linkedUser,
                'updated',
                $employee->updated_at?->format('Y-m-d\\TH:i:s.uP'),
            );
        }
    }
}
