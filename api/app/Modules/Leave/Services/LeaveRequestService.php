<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeaveRequestService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly LeaveBalanceService $balances,
        private readonly ApprovalService $approvals,
    ) {}

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = LeaveRequest::query()
            ->with(['employee:id,employee_no,first_name,middle_name,last_name,suffix,department_id', 'leaveType', 'deptApprover:id,name', 'hrApprover:id,name']);

        if (!empty($filters['employee_id'])) {
            $empId = \App\Common\Support\HashIdFilter::decode(
                $filters['employee_id'], \App\Modules\HR\Models\Employee::class,
            );
            if ($empId) $q->where('employee_id', $empId);
        }
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['from'])) $q->where('start_date', '>=', $filters['from']);
        if (!empty($filters['to'])) $q->where('end_date', '<=', $filters['to']);

        // Row-level filtering. Admin and HR Officer see everything.
        // Department Head sees own + their department's requests.
        // Everyone else sees only their own.
        if ($user) {
            $roleSlug = $user->role?->slug;
            $isAdmin = $roleSlug === 'system_admin';
            $isHr    = $user->hasPermission('leave.approve_hr');
            if (! $isAdmin && ! $isHr) {
                $isDeptHead = $user->hasPermission('leave.approve_dept');
                $employeeId = $user->employee_id;
                if ($isDeptHead) {
                    $deptId = \App\Modules\HR\Models\Employee::query()->whereKey($employeeId)->value('department_id');
                    $q->where(function ($qq) use ($employeeId, $deptId) {
                        $qq->where('employee_id', $employeeId);
                        if ($deptId) {
                            $qq->orWhereHas('employee', fn ($e) => $e->where('department_id', $deptId));
                        }
                    });
                } else {
                    $q->where('employee_id', $employeeId);
                }
            }
        }

        return $q->orderByDesc('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function submit(int $employeeId, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($employeeId, $data) {
            $start = CarbonImmutable::parse($data['start_date']);
            $end   = CarbonImmutable::parse($data['end_date']);
            if ($end->lt($start)) {
                throw new \InvalidArgumentException('End date must be on or after start date.');
            }

            $days = $this->businessDaysInclusive($start, $end);
            $year = $start->year;

            $type = LeaveType::findOrFail($data['leave_type_id']);

            // Balance check
            $bal = \App\Modules\Leave\Models\EmployeeLeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $type->id)
                ->where('year', $year)
                ->first();
            if ($bal && (float) $bal->remaining < $days) {
                throw new RuntimeException("Insufficient leave balance ({$bal->remaining} remaining; {$days} requested).");
            }

            // Overlap check
            $overlap = LeaveRequest::query()
                ->where('employee_id', $employeeId)
                ->whereIn('status', [LeaveRequestStatus::PendingDept->value, LeaveRequestStatus::PendingHr->value, LeaveRequestStatus::Approved->value])
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                      ->orWhereBetween('end_date', [$start->toDateString(), $end->toDateString()])
                      ->orWhere(function ($qq) use ($start, $end) {
                          $qq->where('start_date', '<=', $start->toDateString())
                             ->where('end_date', '>=', $end->toDateString());
                      });
                })
                ->exists();
            if ($overlap) {
                throw new RuntimeException('You already have a leave request for these dates.');
            }

            $req = LeaveRequest::create([
                'leave_request_no' => $this->sequences->generate('leave_request'),
                'employee_id'      => $employeeId,
                'leave_type_id'    => $type->id,
                'start_date'       => $start->toDateString(),
                'end_date'         => $end->toDateString(),
                'days'             => $days,
                'reason'           => $data['reason'] ?? null,
                'document_path'    => $data['document_path'] ?? null,
                'status'           => LeaveRequestStatus::PendingDept->value,
            ]);

            $this->approvals->submit($req, 'leave_request');

            return $req->load(['employee', 'leaveType']);
        });
    }

    public function approveDept(LeaveRequest $req, User $approver, ?string $remarks = null): LeaveRequest
    {
        return DB::transaction(function () use ($req, $approver, $remarks) {
            if ($req->status !== LeaveRequestStatus::PendingDept) {
                throw new RuntimeException('Only requests pending department head approval can be approved here.');
            }
            $this->approvals->approve($req, $approver, $remarks);

            $req->update([
                'status'           => LeaveRequestStatus::PendingHr->value,
                'dept_approver_id' => $approver->id,
                'dept_approved_at' => now(),
            ]);

            return $req->fresh(['employee', 'leaveType', 'deptApprover']);
        });
    }

    public function approveHR(LeaveRequest $req, User $approver, ?string $remarks = null): LeaveRequest
    {
        return DB::transaction(function () use ($req, $approver, $remarks) {
            if ($req->status !== LeaveRequestStatus::PendingHr) {
                throw new RuntimeException('Only requests pending HR approval can be approved here.');
            }
            $this->approvals->approve($req, $approver, $remarks);

            $req->update([
                'status'         => LeaveRequestStatus::Approved->value,
                'hr_approver_id' => $approver->id,
                'hr_approved_at' => now(),
            ]);

            // Side effect 1: deduct leave balance.
            $year = (int) $req->start_date->format('Y');
            $this->balances->consume($req->employee_id, $req->leave_type_id, $year, (float) $req->days);

            // Side effect 2: mark attendance days as on_leave.
            $this->markAttendance($req);

            return $req->fresh(['employee', 'leaveType', 'deptApprover', 'hrApprover']);
        });
    }

    public function reject(LeaveRequest $req, User $approver, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($req, $approver, $reason) {
            if (! in_array($req->status, [LeaveRequestStatus::PendingDept, LeaveRequestStatus::PendingHr], true)) {
                throw new RuntimeException('Only pending requests can be rejected.');
            }
            $this->approvals->reject($req, $approver, $reason);
            $req->update([
                'status'           => LeaveRequestStatus::Rejected->value,
                'rejection_reason' => $reason,
            ]);
            return $req->fresh(['employee', 'leaveType']);
        });
    }

    public function cancel(LeaveRequest $req, User $user): LeaveRequest
    {
        return DB::transaction(function () use ($req, $user) {
            if (in_array($req->status, [LeaveRequestStatus::Cancelled, LeaveRequestStatus::Rejected], true)) {
                throw new RuntimeException('Already finalized.');
            }
            $wasApproved = $req->status === LeaveRequestStatus::Approved;
            $req->update(['status' => LeaveRequestStatus::Cancelled->value]);

            if ($wasApproved) {
                $year = (int) $req->start_date->format('Y');
                $this->balances->restore($req->employee_id, $req->leave_type_id, $year, (float) $req->days);
                $this->unmarkAttendance($req);
            }

            return $req->fresh(['employee', 'leaveType']);
        });
    }

    private function businessDaysInclusive(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $count = 0;
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            if ($d->dayOfWeek !== \Carbon\Carbon::SUNDAY) {
                $count++;
            }
        }
        return (float) $count;
    }

    private function markAttendance(LeaveRequest $req): void
    {
        for ($d = CarbonImmutable::parse($req->start_date); $d->lte(CarbonImmutable::parse($req->end_date)); $d = $d->addDay()) {
            Attendance::updateOrCreate(
                ['employee_id' => $req->employee_id, 'date' => $d->toDateString()],
                [
                    'status'           => AttendanceStatus::OnLeave->value,
                    'is_manual_entry'  => false,
                    'remarks'          => "leave:{$req->leave_request_no}",
                    // Keep computed numeric fields zero — payroll will apply leave-pay logic.
                    'regular_hours'    => 0,
                    'overtime_hours'   => 0,
                    'night_diff_hours' => 0,
                    'tardiness_minutes'=> 0,
                    'undertime_minutes'=> 0,
                    'day_type_rate'    => 1.00,
                ],
            );
        }
    }

    private function unmarkAttendance(LeaveRequest $req): void
    {
        Attendance::query()
            ->where('employee_id', $req->employee_id)
            ->whereBetween('date', [$req->start_date, $req->end_date])
            ->where('remarks', "leave:{$req->leave_request_no}")
            ->delete();
    }
}
