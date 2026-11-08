<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin \App\Modules\Payroll\Models\Payroll
 */
class PayrollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->whenLoaded('employee');
        $actionContext = null;
        $attendanceSummary = null;

        // Action context is only needed for the individual payroll detail and
        // recompute response. Leave collections free of per-row permission and
        // state checks.
        if ($request->route('payroll') instanceof Payroll) {
            $actor = $request->user();
            $mayRecompute = $actor?->hasPermission('payroll.periods.compute') ?? false;
            $periodStatus = $this->whenLoaded('period', fn () => $this->period->status);
            $period = $this->resource->relationLoaded('period') ? $this->period : null;
            $periods = app(\App\Modules\Payroll\Services\PayrollPeriodService::class);
            $canRecompute = $mayRecompute
                && $periodStatus instanceof PayrollPeriodStatus
                && $periodStatus->isComputable()
                && $period instanceof PayrollPeriod
                && $periods->hasPeriodEnded($period);
            $computeAvailableOn = $mayRecompute
                && $periodStatus instanceof PayrollPeriodStatus
                && $periodStatus->isComputable()
                && $period instanceof PayrollPeriod
                ? $periods->computeAvailableOn($period)
                : null;

            $actionContext = [
                'permissions' => ['recompute' => $mayRecompute],
                'available_actions' => ['recompute' => $canRecompute],
                'compute_available_on' => $computeAvailableOn,
            ];

            if ($period instanceof PayrollPeriod) {
                $attendanceSummary = $this->attendanceSummary($period);
            }
        }

        return [
            'id'              => $this->hash_id,
            'permissions'     => $this->when($actionContext !== null, $actionContext['permissions'] ?? []),
            'available_actions' => $this->when($actionContext !== null, $actionContext['available_actions'] ?? []),
            'compute_available_on' => $this->when($actionContext !== null, $actionContext['compute_available_on'] ?? null),
            'attendance_summary' => $this->when($attendanceSummary !== null, $attendanceSummary),
            'period_id'       => $this->whenLoaded('period', fn () => $this->period->hash_id),
            'employee'        => $employee ? [
                'id'           => $this->employee->hash_id,
                'employee_no'  => $this->employee->employee_no,
                'full_name'    => $this->employee->full_name,
                'department'   => $this->employee->department?->name,
                'position'     => $this->employee->position?->title,
            ] : null,

            'pay_type'        => $this->pay_type,
            'days_worked'     => $this->days_worked,

            // The pay period, not computed_at, is the authoritative employee
            // facing period/status contract.
            'period_start'    => $this->whenLoaded('period', fn () => $this->period->period_start?->toDateString()),
            'period_end'      => $this->whenLoaded('period', fn () => $this->period->period_end?->toDateString()),
            'period_payroll_date' => $this->whenLoaded('period', fn () => $this->period->payroll_date?->toDateString()),
            'period_status'   => $this->whenLoaded('period', fn () => $this->period->status?->value),
            'period_status_label' => $this->whenLoaded('period', fn () => $this->period->status?->label()),

            'basic_pay'       => $this->basic_pay,
            'leave_pay'       => $this->leave_pay,
            'overtime_pay'    => $this->overtime_pay,
            'night_diff_pay'  => $this->night_diff_pay,
            'holiday_pay'     => $this->holiday_pay,
            'tardiness_deduction' => $this->tardiness_deduction,
            'undertime_deduction' => $this->undertime_deduction,
            'gross_pay'       => $this->gross_pay,

            'sss_ee'          => $this->sss_ee,
            'sss_er'          => $this->sss_er,
            'philhealth_ee'   => $this->philhealth_ee,
            'philhealth_er'   => $this->philhealth_er,
            'pagibig_ee'      => $this->pagibig_ee,
            'pagibig_er'      => $this->pagibig_er,
            'withholding_tax' => $this->withholding_tax,

            'loan_deductions'   => $this->loan_deductions,
            'other_deductions'  => $this->other_deductions,
            'adjustment_amount' => $this->adjustment_amount,
            'total_deductions'  => $this->total_deductions,
            'net_pay'           => $this->net_pay,

            'error_message'   => $this->error_message,
            'computed_at'     => optional($this->computed_at)->toIso8601String(),

            // ADV1 — Disbursement status from the parent period so payslip
            // pages can display "Disbursed on [date]" to employees.
            'period_disbursement_status' => $this->whenLoaded('period', fn () => $this->period->disbursement_status ?? 'pending'),

            'deduction_details' => PayrollDeductionDetailResource::collection($this->whenLoaded('deductionDetails')),

            'created_at'      => optional($this->created_at)->toIso8601String(),
            'updated_at'      => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Current recorded DTR details for the payroll cutoff. Missing DTR dates
     * are deliberately not inferred as absences; only explicit Attendance rows
     * contribute to these counts.
     *
     * @return array{
     *   dtr_record_count: int,
     *   present_days: int,
     *   late_days: int,
     *   absent_days: int,
     *   half_days: int,
     *   leave_days: int,
     *   holiday_days: int,
     *   rest_days: int,
     *   records: array<int, array<string, mixed>>
     * }
     */
    private function attendanceSummary(PayrollPeriod $period): array
    {
        $records = Attendance::query()
            ->where('employee_id', $this->employee_id)
            ->whereBetween('date', [$period->period_start, $period->period_end])
            ->orderBy('date')
            ->get([
                'date', 'time_in', 'time_out', 'regular_hours', 'overtime_hours',
                'night_diff_hours', 'tardiness_minutes', 'undertime_minutes', 'status',
            ]);

        return [
            'dtr_record_count' => $records->count(),
            'present_days' => $records->filter(fn (Attendance $attendance): bool =>
                (float) $attendance->regular_hours > 0
                && ! in_array($attendance->status, [AttendanceStatus::Absent, AttendanceStatus::OnLeave], true)
            )->count(),
            'late_days' => $records->filter(fn (Attendance $attendance): bool =>
                $attendance->tardiness_minutes > 0 || $attendance->status === AttendanceStatus::Late
            )->count(),
            'absent_days' => $records->where('status', AttendanceStatus::Absent)->count(),
            'half_days' => $records->where('status', AttendanceStatus::Halfday)->count(),
            'leave_days' => $records->where('status', AttendanceStatus::OnLeave)->count(),
            'holiday_days' => $records->where('status', AttendanceStatus::Holiday)->count(),
            'rest_days' => $records->where('status', AttendanceStatus::RestDay)->count(),
            'records' => $records->map(function (Attendance $attendance): array {
                $status = $attendance->status instanceof AttendanceStatus
                    ? $attendance->status->value
                    : (string) $attendance->status;

                return [
                    'date' => $attendance->date?->toDateString(),
                    'status' => $status,
                    'status_label' => $attendance->status instanceof AttendanceStatus
                        ? $attendance->status->label()
                        : Str::headline($status),
                    'regular_hours' => (string) $attendance->regular_hours,
                    'overtime_hours' => (string) $attendance->overtime_hours,
                    'night_diff_hours' => (string) $attendance->night_diff_hours,
                    'tardiness_minutes' => (int) $attendance->tardiness_minutes,
                    'undertime_minutes' => (int) $attendance->undertime_minutes,
                ];
            })->values()->all(),
        ];
    }
}
