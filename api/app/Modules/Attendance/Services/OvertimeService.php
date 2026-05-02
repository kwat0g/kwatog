<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OvertimeService
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = OvertimeRequest::query()->with(['employee:id,employee_no,first_name,middle_name,last_name,suffix', 'approver:id,name']);
        if (!empty($filters['employee_id'])) $q->where('employee_id', $filters['employee_id']);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['from'])) $q->where('date', '>=', $filters['from']);
        if (!empty($filters['to'])) $q->where('date', '<=', $filters['to']);
        return $q->orderByDesc('date')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data): OvertimeRequest
    {
        return DB::transaction(fn () => OvertimeRequest::create($data + ['status' => OvertimeStatus::Pending->value])
            ->load('employee'));
    }

    public function approve(OvertimeRequest $ot, User $approver, ?string $remarks = null): OvertimeRequest
    {
        return DB::transaction(function () use ($ot, $approver, $remarks) {
            if ($ot->status !== OvertimeStatus::Pending) {
                throw new RuntimeException('Only pending overtime requests can be approved.');
            }
            $ot->update([
                'status'      => OvertimeStatus::Approved->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);
            // Recompute attendance for that day if it exists.
            $this->attendance->recomputeForEmployeeOnDate($ot->employee_id, $ot->date->toDateString());
            return $ot->fresh(['employee', 'approver']);
        });
    }

    public function reject(OvertimeRequest $ot, User $approver, string $reason): OvertimeRequest
    {
        return DB::transaction(function () use ($ot, $approver, $reason) {
            if ($ot->status !== OvertimeStatus::Pending) {
                throw new RuntimeException('Only pending overtime requests can be rejected.');
            }
            $ot->update([
                'status'           => OvertimeStatus::Rejected->value,
                'approved_by'      => $approver->id,
                'approved_at'      => now(),
                'rejection_reason' => $reason,
            ]);
            return $ot->fresh(['employee', 'approver']);
        });
    }
}
