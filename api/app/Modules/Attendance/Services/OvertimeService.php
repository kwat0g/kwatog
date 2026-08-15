<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OvertimeService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Pure helper — minutes the punch-out is past the resolved shift end.
     * Clamps below zero to 0 so the caller never persists negative OT.
     */
    public function extraMinutesPastShiftEnd(string $shiftEnd, string $timeOut): int
    {
        $end = \Carbon\CarbonImmutable::parse($shiftEnd);
        $out = \Carbon\CarbonImmutable::parse($timeOut);
        if ($out->lessThanOrEqualTo($end)) {
            return 0;
        }
        return (int) $end->diffInMinutes($out);
    }

    /**
     * Idempotent: scans an Attendance row and creates a draft OT request
     * if punches show work past shift end beyond the configured threshold.
     *
     * Returns the created OT row, or null if any guard short-circuited.
     */
    public function autoDetectFromAttendance(\App\Modules\Attendance\Models\Attendance $a): ?OvertimeRequest
    {
        $enabled = $this->settings->get('attendance.auto_ot_detect.enabled');
        if (! is_bool($enabled)) {
            throw new BusinessRuleException('Required business setting attendance.auto_ot_detect.enabled is missing or invalid.');
        }
        if (! $enabled) {
            return null;
        }
        $thresholdValue = $this->settings->get('attendance.auto_ot_detect.threshold_minutes');
        if (! is_numeric($thresholdValue) || (int) $thresholdValue < 0) {
            throw new BusinessRuleException('Required business setting attendance.auto_ot_detect.threshold_minutes is missing or invalid.');
        }
        $threshold = (int) $thresholdValue;

        if (! $a->time_in || ! $a->time_out) {
            return null;
        }

        $a->loadMissing('shift');
        $shift = $a->shift;
        if (! $shift) {
            return null; // no shift → cannot anchor end time
        }

        // Build the shift-end anchor on the attendance date.
        $date = \Carbon\CarbonImmutable::parse((string) $a->date);
        $endTime = $shift->end_time instanceof \Carbon\CarbonInterface
            ? $shift->end_time->format('H:i:s')
            : (string) $shift->end_time;
        $startTime = $shift->start_time instanceof \Carbon\CarbonInterface
            ? $shift->start_time->format('H:i:s')
            : (string) $shift->start_time;

        $shiftEnd = \Carbon\CarbonImmutable::parse($date->toDateString() . ' ' . $endTime);
        $shiftStart = \Carbon\CarbonImmutable::parse($date->toDateString() . ' ' . $startTime);
        if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
            $shiftEnd = $shiftEnd->addDay();
        }

        $extra = $this->extraMinutesPastShiftEnd(
            $shiftEnd->toDateTimeString(),
            \Carbon\CarbonImmutable::parse($a->time_out)->toDateTimeString(),
        );
        if ($extra < $threshold) {
            return null;
        }

        $hours = round($extra / 60, 1);

        try {
            return DB::transaction(function () use ($a, $hours, $extra): ?OvertimeRequest {
                // Serialize normal replays. The partial unique index below is
                // still authoritative when two workers both observe no row.
                $existing = OvertimeRequest::query()
                    ->where('employee_id', $a->employee_id)
                    ->whereDate('date', $a->date)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return null;
                }

                $ot = OvertimeRequest::create([
                    'employee_id'      => $a->employee_id,
                    'date'             => $a->date,
                    'hours_requested'  => $hours,
                    'reason'           => "Auto-detected from biometric punch (worked {$extra} minutes past shift end).",
                    'is_auto_detected' => true,
                ]);
                // Default DB status is 'pending' — no need to set explicitly.
                $ot->load('employee');
                app(OutboxService::class)->record(new OvertimeRequestSubmitted($ot));
                return $ot;
            });
        } catch (QueryException $e) {
            // A concurrent replay may win the partial unique index after both
            // workers pass the lock-then-check. Treat that loser as an
            // idempotent replay and never emit a second outbox event.
            if (! $this->isAutoSourceUniqueViolation($e)) {
                throw $e;
            }

            $existing = OvertimeRequest::query()
                ->where('employee_id', $a->employee_id)
                ->whereDate('date', $a->date)
                ->where('is_auto_detected', true)
                ->exists();
            if ($existing) {
                return null;
            }

            throw $e;
        }
    }

    private function isAutoSourceUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        return in_array($code, ['23000', '23505'], true)
            && (str_contains($exception->getMessage(), 'overtime_requests_auto_source_unique')
                || str_contains($exception->getMessage(), 'overtime_requests.employee_id, overtime_requests.date'));
    }

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = OvertimeRequest::query()->with(['employee:id,employee_no,first_name,middle_name,last_name,suffix,department_id', 'approver:id,name,role_id']);
        if (!empty($filters['employee_id'])) {
            $empId = \App\Common\Support\HashIdFilter::decode(
                $filters['employee_id'], \App\Modules\HR\Models\Employee::class,
            );
            if ($empId) $q->where('employee_id', $empId);
        }
        if (!empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $q->where(fn ($qq) => $qq
                ->whereHas('employee', fn ($e) => $e->where('employee_no', 'ilike', "%{$term}%")
                    ->orWhere('first_name', 'ilike', "%{$term}%")
                    ->orWhere('middle_name', 'ilike', "%{$term}%")
                    ->orWhere('last_name', 'ilike', "%{$term}%")));
        }
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['from'])) $q->where('date', '>=', $filters['from']);
        if (!empty($filters['to'])) $q->where('date', '<=', $filters['to']);

        // Row-level filtering. Admin/approvers see all; otherwise own only.
        if ($user) {
            $roleSlug = $user->role?->slug;
            $isAdmin = $roleSlug === 'system_admin';
            $isApprover = $user->hasPermission('attendance.ot.approve');
            if (! $isAdmin && ! $isApprover) {
                $q->where('employee_id', $user->employee_id);
            } elseif (! $isAdmin && $isApprover) {
                $deptId = \App\Modules\HR\Models\Employee::query()->whereKey($user->employee_id)->value('department_id');
                $q->where(function ($qq) use ($user, $deptId) {
                    $qq->where('employee_id', $user->employee_id);
                    if ($deptId) $qq->orWhereHas('employee', fn ($e) => $e->where('department_id', $deptId));
                });
            }
        }

        return $q->orderByDesc('date')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data): OvertimeRequest
    {
        $ot = DB::transaction(function () use ($data): OvertimeRequest {
            $ot = OvertimeRequest::create($data + ['status' => OvertimeStatus::Pending->value])
                ->load('employee');
            app(OutboxService::class)->record(new OvertimeRequestSubmitted($ot));
            return $ot;
        });

        return $ot;
    }

    public function approve(OvertimeRequest $ot, User $approver, ?string $remarks = null): OvertimeRequest
    {
        $result = DB::transaction(function () use ($ot, $approver, $remarks) {
            $authoritative = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($ot->id)
                ->loadMissing('employee.user');

            if ($authoritative->status !== OvertimeStatus::Pending) {
                throw new BusinessRuleException('Only pending overtime requests can be approved.');
            }

            // SoD: the requesting employee must not approve their own OT. The
            // user<->employee link is one-directional (users.employee_id ->
            // employees.id), exposed via Employee::user(); there is NO
            // employees.user_id column, so resolve the submitter through the
            // relationship rather than a non-existent attribute (OGAMI audit
            // DEFECT-1 — the old $ot->employee?->user_id was always null).
            $submitterId = $authoritative->employee?->user?->id;
            if ($submitterId !== null && (int) $submitterId === (int) $approver->id) {
                throw new BusinessRuleException('You cannot approve your own overtime request.');
            }

            $authoritative->update([
                'status'      => OvertimeStatus::Approved->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
            ]);
            // Recompute attendance for that day if it exists.
            $this->attendance->recomputeForEmployeeOnDate($authoritative->employee_id, $authoritative->date->toDateString());
            $result = $authoritative->fresh(['employee', 'approver']);
            app(OutboxService::class)->record(new OvertimeRequestDecided($result, true));
            return $result;
        });

        return $result;
    }

    /**
     * L-23 — Bulk approve. Returns ['approved' => OT[], 'failed' => [['id'=>X, 'reason'=>...]]].
     * Per-row try/catch so one bad row doesn't abort the batch.
     *
     * @param array<int, int> $otIds raw integer IDs (post HashID decode)
     * @return array{approved: array<int, OvertimeRequest>, failed: array<int, array{id:int, reason:string}>}
     */
    public function bulkApprove(array $otIds, User $approver, ?string $remarks = null): array
    {
        $approved = [];
        $failed   = [];

        foreach ($otIds as $id) {
            try {
                $ot = OvertimeRequest::query()->find($id);
                if (! $ot) {
                    $failed[] = ['id' => $id, 'reason' => 'Not found.'];
                    continue;
                }
                $approved[] = $this->approve($ot, $approver, $remarks);
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return ['approved' => $approved, 'failed' => $failed];
    }

    public function reject(OvertimeRequest $ot, User $approver, string $reason): OvertimeRequest
    {
        $result = DB::transaction(function () use ($ot, $approver, $reason) {
            $authoritative = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($ot->id);

            if ($authoritative->status !== OvertimeStatus::Pending) {
                throw new BusinessRuleException('Only pending overtime requests can be rejected.');
            }
            $authoritative->update([
                'status'           => OvertimeStatus::Rejected->value,
                'approved_by'      => $approver->id,
                'approved_at'      => now(),
                'rejection_reason' => $reason,
                'cancelled_by'     => null,
                'cancelled_at'     => null,
            ]);
            $result = $authoritative->fresh(['employee', 'approver']);
            app(OutboxService::class)->record(new OvertimeRequestDecided($result, false));
            return $result;
        });

        return $result;
    }

    /**
     * Cancel a pending overtime request. The owning employee may withdraw
     * their own request; admins and OT approvers may cancel any pending one
     * (e.g. the work was already covered). Reuses the rejected-state columns
     * so the employee sees the withdrawal reason in their history.
     */
    public function cancel(OvertimeRequest $ot, User $user, string $reason = 'Cancelled.'): OvertimeRequest
    {
        $result = DB::transaction(function () use ($ot, $user, $reason) {
            $authoritative = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($ot->id);

            if ($authoritative->status !== OvertimeStatus::Pending) {
                throw new BusinessRuleException('Only pending overtime requests can be cancelled.');
            }
            $authoritative->update([
                'status'           => OvertimeStatus::Rejected->value,
                'approved_by'      => null,
                'approved_at'      => null,
                'rejection_reason' => $reason,
                'cancelled_by'     => $user->id,
                'cancelled_at'     => now(),
            ]);
            $result = $authoritative->fresh(['employee', 'approver']);
            app(OutboxService::class)->record(new OvertimeRequestDecided($result, false));
            return $result;
        });

        return $result;
    }

    /**
     * Reopen only a request that this same employee explicitly cancelled.
     * An approver's rejection is intentionally not restorable from self-service.
     */
    public function restore(OvertimeRequest $ot, User $user): OvertimeRequest
    {
        return DB::transaction(function () use ($ot, $user): OvertimeRequest {
            $authoritative = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($ot->id);

            if ($authoritative->status !== OvertimeStatus::Rejected
                || $authoritative->cancelled_at === null
                || (int) $authoritative->cancelled_by !== (int) $user->id
                || (int) $authoritative->employee_id !== (int) $user->employee_id) {
                throw new BusinessRuleException('Only your own cancelled overtime request can be restored.');
            }

            $authoritative->update([
                'status'           => OvertimeStatus::Pending->value,
                'approved_by'      => null,
                'approved_at'      => null,
                'rejection_reason' => null,
                'cancelled_by'     => null,
                'cancelled_at'     => null,
            ]);

            $result = $authoritative->fresh(['employee', 'approver']);
            app(OutboxService::class)->record(new OvertimeRequestSubmitted($result));

            return $result;
        });
    }

    /**
     * Settings-driven request constraints shared by the SPA create form.
     * Mirrors the self-service payload shape (minimum_hours, maximum_hours,
     * premium_multiplier) and adds the date window so the form can render
     * matching min/max attributes and hints.
     */
    public function options(): array
    {
        return [
            'statuses'           => array_map(
                static fn (OvertimeStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                OvertimeStatus::cases(),
            ),
            'minimum_hours'      => (float) $this->settings->requiredInt('attendance.ot.minimum_minutes', 0) / 60,
            'maximum_hours'      => $this->settings->requiredFloat('attendance.ot.admin_max_hours', 0),
            'request_min_hours'  => $this->settings->requiredFloat('attendance.ot.request_min_hours', 0),
            'request_future_days'=> $this->settings->requiredInt('attendance.ot.request_future_days', 0),
            'request_past_days'  => $this->settings->requiredInt('attendance.ot.request_past_days', 0),
            'premium_multiplier' => $this->settings->requiredFloat('payroll.overtime.ordinary_multiplier', 1),
        ];
    }
}
