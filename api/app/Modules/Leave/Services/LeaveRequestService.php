<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
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
            ->with(['employee:id,employee_no,first_name,middle_name,last_name,suffix,department_id', 'employee.department:id,name', 'leaveType', 'deptApprover:id,name', 'hrApprover:id,name']);

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

            // M-18 — half-day leave. Must be a single-date request.
            $halfDayPeriod = $data['half_day_period'] ?? null;
            if ($halfDayPeriod !== null) {
                if (! in_array($halfDayPeriod, ['am', 'pm'], true)) {
                    throw new \InvalidArgumentException('half_day_period must be "am" or "pm".');
                }
                if (! $start->isSameDay($end)) {
                    throw new \InvalidArgumentException('Half-day leave must start and end on the same date.');
                }
            }

            $days = $halfDayPeriod !== null
                ? 0.5
                : $this->businessDaysInclusive($start, $end);
            $year = $start->year;

            $type = LeaveType::findOrFail($data['leave_type_id']);

            // Balance check — locked inside the transaction so concurrent
            // requests see the updated balance of whichever commits first.
            $bal = \App\Modules\Leave\Models\EmployeeLeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $type->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();
            if ($bal && (float) $bal->remaining < $days) {
                throw new RuntimeException("Insufficient leave balance ({$bal->remaining} remaining; {$days} requested).");
            }

            // Overlap check — lock overlapping rows to prevent concurrent
            // insertions of overlapping leave requests.
            //
            // M-18 — half-day awareness. The base date-range query stays the
            // same; we add a closure that excludes opposite-half collisions
            // on a single-date request. An AM and a PM request on the same
            // day do not collide. Any full-day on the same date does collide.
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
                ->when($halfDayPeriod !== null, function ($q) use ($halfDayPeriod) {
                    // Single-day half-day request: collides ONLY with rows that
                    // are full-day (half_day_period IS NULL) OR same half.
                    $q->where(function ($qq) use ($halfDayPeriod) {
                        $qq->whereNull('half_day_period')
                           ->orWhere('half_day_period', $halfDayPeriod);
                    });
                })
                ->lockForUpdate()
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
                'half_day_period'  => $halfDayPeriod,
                'reason'           => $data['reason'] ?? null,
                'document_path'    => $data['document_path'] ?? null,
            ]);
            // status + approval fields are non-fillable; service-only writes.
            $req->forceFill(['status' => LeaveRequestStatus::PendingDept->value])->save();

            $this->approvals->submit($req, 'leave_request');

            DB::afterCommit(fn () => LeaveRequestSubmitted::dispatch($req->fresh(['employee', 'employee.department', 'leaveType'])));

            return $req->load(['employee', 'employee.department', 'leaveType']);
        });
    }

    public function approveDept(LeaveRequest $req, User $approver, ?string $remarks = null): LeaveRequest
    {
        return DB::transaction(function () use ($req, $approver, $remarks) {
            if ($req->status !== LeaveRequestStatus::PendingDept) {
                throw new RuntimeException('Only requests pending department head approval can be approved here.');
            }
            $this->approvals->approve($req, $approver, $remarks);

            $req->forceFill([
                'status'           => LeaveRequestStatus::PendingHr->value,
                'dept_approver_id' => $approver->id,
                'dept_approved_at' => now(),
            ])->save();

            DB::afterCommit(fn () => LeaveRequestPendingHR::dispatch($req->fresh(['employee', 'employee.department', 'leaveType'])));

            return $req->fresh(['employee', 'employee.department', 'leaveType', 'deptApprover']);
        });
    }

    public function approveHR(LeaveRequest $req, User $approver, ?string $remarks = null): LeaveRequest
    {
        return DB::transaction(function () use ($req, $approver, $remarks) {
            if ($req->status !== LeaveRequestStatus::PendingHr) {
                throw new RuntimeException('Only requests pending HR approval can be approved here.');
            }
            $this->approvals->approve($req, $approver, $remarks);

            $req->forceFill([
                'status'         => LeaveRequestStatus::Approved->value,
                'hr_approver_id' => $approver->id,
                'hr_approved_at' => now(),
            ])->save();

            // Side effect 1: deduct leave balance.
            $year = (int) $req->start_date->format('Y');
            $this->balances->consume($req->employee_id, $req->leave_type_id, $year, (float) $req->days);

            // Side effect 2: mark attendance days as on_leave.
            $this->markAttendance($req);

            DB::afterCommit(fn () => LeaveRequestApproved::dispatch($req->fresh(['employee', 'employee.department', 'leaveType'])));

            return $req->fresh(['employee', 'employee.department', 'leaveType', 'deptApprover', 'hrApprover']);
        });
    }

    /**
     * T1.7 — Bulk approve LeaveRequests at the department-head stage.
     * Per-row try/catch so one bad row doesn't abort the batch.
     *
     * @param array<int, int> $ids raw integer IDs (post HashID decode)
     * @return array{approved: array<int, LeaveRequest>, failed: array<int, array{id:int, reason:string}>}
     */
    public function bulkApproveDept(array $ids, User $approver, ?string $remarks = null): array
    {
        $approved = [];
        $failed   = [];

        foreach ($ids as $id) {
            try {
                $req = LeaveRequest::query()->find($id);
                if (! $req) {
                    $failed[] = ['id' => $id, 'reason' => 'Not found.'];
                    continue;
                }
                $approved[] = $this->approveDept($req, $approver, $remarks);
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return ['approved' => $approved, 'failed' => $failed];
    }

    /**
     * T1.7 — Bulk approve LeaveRequests at the HR-officer stage.
     * Per-row try/catch so one bad row doesn't abort the batch.
     *
     * @param array<int, int> $ids
     * @return array{approved: array<int, LeaveRequest>, failed: array<int, array{id:int, reason:string}>}
     */
    public function bulkApproveHR(array $ids, User $approver, ?string $remarks = null): array
    {
        $approved = [];
        $failed   = [];

        foreach ($ids as $id) {
            try {
                $req = LeaveRequest::query()->find($id);
                if (! $req) {
                    $failed[] = ['id' => $id, 'reason' => 'Not found.'];
                    continue;
                }
                $approved[] = $this->approveHR($req, $approver, $remarks);
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return ['approved' => $approved, 'failed' => $failed];
    }

    public function reject(LeaveRequest $req, User $approver, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($req, $approver, $reason) {
            if (! in_array($req->status, [LeaveRequestStatus::PendingDept, LeaveRequestStatus::PendingHr], true)) {
                throw new RuntimeException('Only pending requests can be rejected.');
            }
            $this->approvals->reject($req, $approver, $reason);
            $req->forceFill([
                'status'           => LeaveRequestStatus::Rejected->value,
                'rejection_reason' => $reason,
            ])->save();

            DB::afterCommit(fn () => LeaveRequestRejected::dispatch($req->fresh(['employee', 'employee.department', 'leaveType'])));

            return $req->fresh(['employee', 'employee.department', 'leaveType']);
        });
    }

    public function cancel(LeaveRequest $req, User $user): LeaveRequest
    {
        return DB::transaction(function () use ($req, $user) {
            if (in_array($req->status, [LeaveRequestStatus::Cancelled, LeaveRequestStatus::Rejected], true)) {
                throw new RuntimeException('Already finalized.');
            }
            $wasApproved = $req->status === LeaveRequestStatus::Approved;
            $req->forceFill(['status' => LeaveRequestStatus::Cancelled->value])->save();

            if ($wasApproved) {
                $year = (int) $req->start_date->format('Y');
                $this->balances->restore($req->employee_id, $req->leave_type_id, $year, (float) $req->days);
                $this->unmarkAttendance($req);
            }

            return $req->fresh(['employee', 'employee.department', 'leaveType']);
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
