<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Enums\ProfileUpdateStatus;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Resources\EmployeeTrainingResource;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use App\Modules\HR\Services\SelfServiceDocumentService;
use App\Modules\HR\Services\SelfServiceHomeService;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Loans\Services\LoanService;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * U3 — Employee self-service endpoints. The current user must be linked to
 * an employee row; every endpoint scopes data to that employee only.
 */
class SelfServiceController
{
    public function __construct(
        private readonly ProfileUpdateRequestService $profileUpdates,
        private readonly OvertimeService $overtime,
        private readonly SelfServiceDocumentService $documents,
        private readonly SelfServiceHomeService $home,
        private readonly SettingsService $settings,
        private readonly LoanService $loans,
    ) {}

    private function currentEmployee(Request $request): Employee
    {
        $user = $request->user();
        abort_if(! $user || ! $user->employee_id, 403, 'No employee linked to this account.');

        /** @var Employee|null $emp */
        $emp = Employee::query()
            ->with(['department', 'position'])
            ->whereKey($user->employee_id)
            ->first();
        abort_if(! $emp, 404, 'Employee not found.');

        return $emp;
    }

    public function home(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $today = now()->toDateString();

        $summary = $this->home->summary($employee);

        return response()->json([
            'data' => [
                'greeting' => $this->greeting(),
                'today' => $today,
                'employee' => [
                    'id' => $employee->hash_id,
                    'employee_no' => $employee->employee_no,
                    'first_name' => $employee->first_name,
                    'full_name' => $employee->full_name,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->title,
                ],
                'todays_shift' => $summary['todays_shift'],
                'leave_balances' => $summary['leave_balances'],
                'leave_balance_policy' => $summary['leave_balance_policy'],
                'pending_count' => $summary['pending_count'],
                'latest_payslip' => $summary['latest_payslip'],
            ],
        ]);
    }

    public function loans(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        if (! Schema::hasTable('employee_loans')) {
            return response()->json(['data' => ['active' => [], 'history' => [], 'loan_types' => $this->loans->types(), 'max_pay_periods' => $this->settings->requiredInt('loans.max_pay_periods', 1, 120)]]);
        }

        $rows = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->get();

        $map = fn (EmployeeLoan $loan) => [
            'id' => $loan->hash_id,
            'loan_type' => $loan->loan_type?->value,
            'loan_type_label' => $loan->loan_type?->label(),
            'principal' => $loan->principal !== null ? (string) $loan->principal : null,
            'outstanding_balance' => $loan->balance !== null ? (string) $loan->balance : null,
            'monthly_amortization' => $loan->monthly_amortization !== null ? (string) $loan->monthly_amortization : null,
            'periods' => (int) ($loan->pay_periods_total ?? 0),
            'periods_remaining' => (int) ($loan->pay_periods_remaining ?? 0),
            'status' => $loan->status?->value,
            'status_label' => $loan->status?->label(),
            'created_at' => optional($loan->created_at)->toIso8601String(),
        ];

        $active = $rows->filter(fn (EmployeeLoan $loan): bool => in_array(
            $loan->status,
            [LoanStatus::Pending, LoanStatus::Active],
            true,
        ))
            ->map($map)->values()->all();
        $history = $rows->reject(fn (EmployeeLoan $loan): bool => in_array(
            $loan->status,
            [LoanStatus::Pending, LoanStatus::Active],
            true,
        ))
            ->map($map)->values()->all();

        return response()->json(['data' => ['active' => $active, 'history' => $history, 'loan_types' => $this->loans->types(), 'max_pay_periods' => $this->settings->requiredInt('loans.max_pay_periods', 1, 120)]]);
    }

    public function applyLoan(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $validated = $request->validate([
            'loan_type' => ['required', Rule::in(collect($this->loans->types())->pluck('value')->all())],
            'amount' => ['required', 'numeric', 'min:1'],
            'periods' => ['required', 'integer', 'min:1', 'max:'.$this->settings->requiredInt('loans.max_pay_periods', 1, 120)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! Schema::hasTable('employee_loans')) {
            abort(503, 'Loans module is not enabled.');
        }

        try {
            $loan = $this->loans->request(
                $employee->id,
                LoanType::from($validated['loan_type']),
                [
                    'principal' => (string) $validated['amount'],
                    'pay_periods' => (int) $validated['periods'],
                    'purpose' => $validated['reason'] ?? null,
                ],
            );
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'message' => 'Loan request submitted for approval.',
            'data' => [
                'id' => $loan->hash_id,
                'status' => $loan->status?->value,
            ],
        ], 201);
    }

    /* ─── Overtime (SS1) ─────────────────────────────────────────────── */

    /**
     * The current employee's overtime requests (pending + history).
     * Always scoped to the session employee — never accepts an employee_id.
     */
    public function overtime(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        if (! Schema::hasTable('overtime_requests')) {
            return response()->json(['data' => [
                'pending' => [], 'history' => [],
                'todays_shift' => $this->todaysShift($employee),
                'hourly_rate' => $this->estimatedHourlyRate($employee),
                'minimum_hours' => $this->overtimeHours('attendance.ot.minimum_minutes') / 60,
                'maximum_hours' => $this->overtimeHours('attendance.ot.maximum_minutes') / 60,
                'premium_multiplier' => $this->settings->requiredFloat('payroll.overtime.ordinary_multiplier', 1),
            ]]);
        }

        $rows = OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->with('approver:id,name')
            ->orderByDesc('date')
            ->limit($this->settings->requiredInt('self_service.history_limit', 1, 500))
            ->get();

        $map = fn (OvertimeRequest $r) => [
            'id' => $r->hash_id,
            'date' => optional($r->date)->toDateString(),
            'hours_requested' => (string) $r->hours_requested,
            'reason' => $r->reason,
            'status' => $r->status?->value,
            'status_label' => $r->status?->label(),
            'rejection_reason' => $r->rejection_reason,
            'approver' => $r->approver?->name,
            'cancelled_at' => optional($r->cancelled_at)->toIso8601String(),
            'can_restore' => $r->status === OvertimeStatus::Rejected
                && $r->cancelled_at !== null
                && (int) $r->cancelled_by === (int) $request->user()?->id,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];

        $pending = $rows->where('status', OvertimeStatus::Pending)->map($map)->values()->all();
        $history = $rows->whereNotIn('status', [OvertimeStatus::Pending])->map($map)->values()->all();

        return response()->json([
            'data' => [
                'pending' => $pending,
                'history' => $history,
                'todays_shift' => $this->todaysShift($employee),
                'hourly_rate' => $this->estimatedHourlyRate($employee),
                'minimum_hours' => $this->overtimeHours('attendance.ot.minimum_minutes') / 60,
                'maximum_hours' => $this->overtimeHours('attendance.ot.maximum_minutes') / 60,
                'premium_multiplier' => $this->settings->requiredFloat('payroll.overtime.ordinary_multiplier', 1),
            ],
        ]);
    }

    /**
     * File an overtime request for the current employee. Reuses the shared
     * OvertimeService so DTR recomputation and audit logging stay consistent.
     */
    public function applyOvertime(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $minHours = $this->settings->requiredFloat('attendance.ot.request_min_hours', 0.01);
        $maxHours = $this->settings->requiredFloat('attendance.ot.request_max_hours', $minHours);
        $futureDays = $this->settings->requiredInt('attendance.ot.request_future_days', 0);
        $pastDays = $this->settings->requiredInt('attendance.ot.request_past_days', 0);
        $today = now()->startOfDay();

        $validated = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$today->copy()->subDays($pastDays)->toDateString(), 'before_or_equal:'.$today->copy()->addDays($futureDays)->toDateString()],
            'hours_requested' => ['required', 'numeric', 'min:'.$minHours, 'max:'.$maxHours],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'date.after_or_equal' => 'Overtime is outside the configured request window.',
            'date.before_or_equal' => 'Overtime is outside the configured request window.',
            'hours_requested.max' => 'Overtime exceeds the configured daily limit.',
            'reason.min' => 'Please provide a meaningful reason (at least 5 characters).',
        ]);

        $ot = $this->overtime->create([
            'employee_id' => $employee->id,
            'date' => $validated['date'],
            'hours_requested' => $validated['hours_requested'],
            'reason' => trim($validated['reason']),
        ]);

        return response()->json([
            'message' => 'Overtime request submitted for Dept Head approval.',
            'data' => ['id' => $ot->hash_id, 'status' => $ot->status?->value],
        ], 201);
    }

    /**
     * Cancel a pending overtime request. Only the owning employee can cancel,
     * and only while the request is still pending.
     */
    public function cancelOvertime(Request $request, string $id): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $decoded = app('hashids')->decode($id);
        abort_if(empty($decoded), 404);

        /** @var OvertimeRequest|null $ot */
        $ot = OvertimeRequest::query()
            ->where('id', $decoded[0])
            ->where('employee_id', $employee->id)
            ->first();

        abort_if(! $ot, 404);
        abort_if($ot->status !== OvertimeStatus::Pending, 422, 'Only pending requests can be cancelled.');

        try {
            $this->overtime->cancel($ot, $request->user(), 'Cancelled by employee.');
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['message' => 'Overtime request cancelled.']);
    }

    /**
     * Restore a cancelled overtime request. Only the owning employee can
     * restore, and only requests that were cancelled can be reopened. Overtime
     * requests are not soft-deletable — cancellation is a status change, so
     * restoring reverts it back to pending.
     */
    public function restoreOvertime(Request $request, string $id): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $decoded = app('hashids')->decode($id);
        abort_if(empty($decoded), 404);

        /** @var OvertimeRequest|null $ot */
        $ot = OvertimeRequest::query()
            ->where('id', $decoded[0])
            ->where('employee_id', $employee->id)
            ->first();

        abort_if(! $ot, 404);
        try {
            $this->overtime->restore($ot, $request->user());
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'message' => 'Overtime request restored.',
            'data' => [
                'id' => $ot->hash_id,
                'status' => OvertimeStatus::Pending->value,
            ],
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $completenessFields = [
            'mobile_number', 'email', 'street_address', 'barangay', 'city', 'province', 'zip_code',
            'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
            'bank_name', 'bank_account_no', 'birth_date', 'nationality', 'gender', 'civil_status', 'date_hired',
        ];
        $missingFields = collect($completenessFields)
            ->filter(fn (string $field): bool => blank($field === 'email' ? ($employee->email ?: $request->user()?->email) : $employee->{$field}))
            ->values()->all();

        return response()->json([
            'data' => [
                'id' => $employee->hash_id,
                'employee_no' => $employee->employee_no,
                'full_name' => $employee->full_name,
                'first_name' => $employee->first_name,
                'middle_name' => $employee->middle_name,
                'last_name' => $employee->last_name,
                'nationality' => $employee->nationality,
                'birth_date' => optional($employee->birth_date)->toDateString(),
                'gender' => $employee->gender?->value,
                'gender_label' => $employee->gender?->label(),
                'civil_status' => $employee->civil_status?->value,
                'civil_status_label' => $employee->civil_status?->label(),
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'date_hired' => optional($employee->date_hired)->toDateString(),
                'date_regularized' => optional($employee->date_regularized)->toDateString(),
                'expected_regularization_date' => $employee->date_regularized || $employee->employment_type?->value !== 'probationary' || ! $employee->date_hired
                    ? null
                    : $employee->date_hired->copy()->addMonthsNoOverflow($this->settings->requiredInt('hr.probation.period_months', 1, 60))->toDateString(),
                'employment_type' => $employee->employment_type?->value,
                'employment_type_label' => $employee->employment_type?->label(),
                'pay_type' => $employee->pay_type?->value,
                'pay_type_label' => $employee->pay_type?->label(),
                'status' => $employee->status?->value,
                'status_label' => $employee->status?->label(),
                'photo_path' => $employee->photo_path,
                // Photo is served through the authenticated /photo endpoint.
                'photo_url' => $employee->photo_path ? "/api/v1/hr/employees/{$employee->hash_id}/photo" : null,
                // Editable
                'mobile_number' => $employee->mobile_number,
                // User login email is authoritative when the legacy employee
                // email column is empty; this keeps the self-service profile
                // populated without inventing contact data.
                'email' => $employee->email ?: $request->user()?->email,
                'street_address' => $employee->street_address,
                'barangay' => $employee->barangay,
                'city' => $employee->city,
                'province' => $employee->province,
                'zip_code' => $employee->zip_code,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_relation' => $employee->emergency_contact_relation,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                // Bank (account masked — last 4 only; change needs HR + Finance).
                'bank_name' => $employee->bank_name,
                'bank_account_last4' => $this->last4($employee->bank_account_no),
                // Government IDs are masked (last 4) — never returned in full.
                'sss_no_last4' => $this->last4($employee->sss_no),
                'philhealth_no_last4' => $this->last4($employee->philhealth_no),
                'pagibig_no_last4' => $this->last4($employee->pagibig_no),
                'tin_last4' => $this->last4($employee->tin),
                'profile_completeness' => [
                    'percent' => (int) round((1 - (count($missingFields) / count($completenessFields))) * 100),
                    'missing_fields' => $missingFields,
                ],
            ],
        ]);
    }

    public function requestProfileUpdate(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $validated = $request->validate([
            'changes' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $req = $this->profileUpdates->submit(
            $employee,
            $request->user(),
            $validated['changes'],
            $validated['note'] ?? null,
        );

        return response()->json([
            'message' => 'Profile update request submitted for HR review.',
            'data' => ['id' => $req->hash_id, 'status' => $req->status],
        ], 201);
    }

    public function profileUpdateRequests(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $rows = $this->profileUpdates->listForEmployee($employee);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->hash_id,
                'status' => $r->status,
                'status_label' => ProfileUpdateStatus::tryFrom((string) $r->status)?->label() ?? (string) $r->status,
                'changes' => $r->changes,
                'note' => $r->note,
                'reviewed_at' => optional($r->reviewed_at)->toIso8601String(),
                'created_at' => optional($r->created_at)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /* ─── Documents (SS3) ────────────────────────────────────────────── */

    /**
     * Catalogue of self-service documents available to the current employee:
     * always-available auto-generated certificates, plus BIR 2316 which is
     * only available once the prior calendar year has been processed.
     */
    public function documents(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $thisYear = (int) now()->format('Y');
        $lastYear = $thisYear - 1;

        // BIR 2316 covers the prior calendar year and is issued after year-end
        // closing (typically January). Available once we have any payroll rows
        // for that year.
        $bir2316Available = Schema::hasTable('payrolls')
            && Payroll::query()
                ->where('employee_id', $employee->id)
                ->whereHas('period', fn ($q) => $q->whereYear('period_start', $lastYear))
                ->exists();

        $catalog = array_values(array_filter((array) $this->settings->get('hr.self_service.certificate_catalog', []), static fn ($certificate): bool => is_array($certificate) && isset($certificate['key'], $certificate['label'])));
        $certificates = array_map(function (array $certificate) use ($bir2316Available, $thisYear, $lastYear): array {
            $key = (string) $certificate['key'];
            $available = $key !== 'bir_2316' || $bir2316Available;
            $noteKey = (string) ($certificate['note'] ?? '');
            $note = match ($noteKey) {
                'current_year' => "Year {$thisYear}",
                'prior_year' => $bir2316Available ? "Year {$lastYear}" : 'Available after year-end closing',
                default => $noteKey,
            };
            return ['key' => $key, 'label' => (string) $certificate['label'], 'available' => $available, 'note' => $note];
        }, $catalog);

        return response()->json([
            'data' => [
                'certificates' => $certificates,
                'current_year' => $thisYear,
                'bir_2316_year' => $lastYear,
            ],
        ]);
    }

    public function employmentCertificate(Request $request): StreamedResponse
    {
        $employee = $this->currentEmployee($request);
        $withSalary = $request->boolean('with_salary');

        return $this->documents->employmentCertificate($employee, $request->user(), $withSalary);
    }

    public function contributionCertificate(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['sss', 'philhealth', 'pagibig'], true), 404);
        $employee = $this->currentEmployee($request);
        $year = (int) ($request->integer('year') ?: now()->format('Y'));

        return $this->documents->contributionCertificate($employee, $type, $year, $request->user());
    }

    public function bir2316(Request $request): StreamedResponse
    {
        $employee = $this->currentEmployee($request);
        $year = (int) ($request->integer('year') ?: ((int) now()->format('Y') - 1));

        return $this->documents->bir2316($employee, $year, $request->user());
    }

    /**
     * T3.4.A — read-only list of the session employee's training records.
     * Always scoped to the session employee — never accepts an employee_id.
     */
    public function trainings(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $rows = EmployeeTraining::query()
            ->with('training')
            ->where('employee_id', $employee->id)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => EmployeeTrainingResource::collection($rows)->resolve(),
        ]);
    }

    private function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * The employee's currently-effective shift (best-effort; tables may be
     * absent in some envs). Used by the OT apply sheet to show "your shift
     * today" and the OT window.
     *
     * @return array{name:string, time_in:string, time_out:string}|null
     */
    private function todaysShift(Employee $employee): ?array
    {
        if (! Schema::hasTable('employee_shift_assignments') || ! Schema::hasTable('shifts')) {
            return null;
        }

        $today = now()->toDateString();
        $row = DB::table('employee_shift_assignments as a')
            ->join('shifts as s', 's.id', '=', 'a.shift_id')
            ->where('a.employee_id', $employee->id)
            ->where('a.effective_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('a.end_date')
                    ->orWhere('a.end_date', '>=', $today);
            })
            ->orderByDesc('a.effective_date')
            ->select('s.name', 's.start_time as time_in', 's.end_time as time_out')
            ->first();

        return $row
            ? ['name' => $row->name, 'time_in' => (string) $row->time_in, 'time_out' => (string) $row->time_out]
            : null;
    }

    /**
     * Rough hourly rate for the OT estimate on the apply sheet — derived from
     * the same basis the payroll engine uses (daily ÷ 8, monthly ÷ 22 ÷ 8).
     * Display-only; the authoritative figure is computed at payroll run.
     */
    private function estimatedHourlyRate(Employee $employee): ?string
    {
        $monthly = $employee->monthlyEquivalentSalary();
        $daily = $monthly !== null
            ? (float) $monthly / $this->settings->requiredInt('payroll.work_days_per_month', 1)
            : null;

        if ($daily === null || $daily <= 0) {
            return null;
        }

        return number_format($daily / $this->settings->requiredInt('payroll.hours_per_day', 1), 2, '.', '');
    }

    private function overtimeHours(string $key): float
    {
        return (float) $this->settings->requiredInt($key, 0, 1440);
    }

    private function last4(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $len = mb_strlen($value);

        return $len <= 4 ? str_repeat('•', $len) : '••••'.mb_substr($value, -4);
    }
}
