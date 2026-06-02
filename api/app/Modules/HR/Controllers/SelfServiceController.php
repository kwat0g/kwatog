<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use App\Modules\HR\Services\SelfServiceDocumentService;
use App\Modules\HR\Services\SelfServiceHomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
                'today'    => $today,
                'employee' => [
                    'id'          => $employee->hash_id,
                    'employee_no' => $employee->employee_no,
                    'first_name'  => $employee->first_name,
                    'full_name'   => $employee->full_name,
                    'department'  => $employee->department?->name,
                    'position'    => $employee->position?->title,
                ],
                'todays_shift'    => $summary['todays_shift'],
                'leave_balances'  => $summary['leave_balances'],
                'pending_count'   => $summary['pending_count'],
                'latest_payslip'  => $summary['latest_payslip'],
            ],
        ]);
    }

    public function loans(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        if (! Schema::hasTable('employee_loans')) {
            return response()->json(['data' => ['active' => [], 'history' => []]]);
        }

        $rows = DB::table('employee_loans')
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->get();

        $map = fn ($r) => [
            'id'                     => app('hashids')->encode((int) $r->id),
            'loan_type'              => $r->loan_type ?? null,
            'principal'              => (string) ($r->principal ?? '0.00'),
            'outstanding_balance'    => (string) ($r->outstanding_balance ?? '0.00'),
            'monthly_amortization'   => (string) ($r->monthly_amortization ?? '0.00'),
            'periods'                => (int) ($r->periods ?? 0),
            'periods_remaining'      => (int) ($r->periods_remaining ?? 0),
            'status'                 => (string) ($r->status ?? 'unknown'),
            'created_at'             => (string) ($r->created_at ?? ''),
        ];

        $active = $rows->whereIn('status', ['approved', 'in_progress', 'active'])
            ->map($map)->values()->all();
        $history = $rows->whereNotIn('status', ['approved', 'in_progress', 'active'])
            ->map($map)->values()->all();

        return response()->json(['data' => compact('active', 'history')]);
    }

    public function applyLoan(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $validated = $request->validate([
            'loan_type' => ['required', 'string', 'max:30'],
            'amount'    => ['required', 'numeric', 'min:1'],
            'periods'   => ['required', 'integer', 'min:1', 'max:24'],
            'reason'    => ['nullable', 'string', 'max:500'],
        ]);

        if (! Schema::hasTable('employee_loans')) {
            abort(503, 'Loans module is not enabled.');
        }

        $id = DB::table('employee_loans')->insertGetId([
            'employee_id'           => $employee->id,
            'loan_type'             => $validated['loan_type'],
            'principal'             => $validated['amount'],
            'outstanding_balance'   => $validated['amount'],
            'monthly_amortization'  => round(((float) $validated['amount']) / max(1, (int) $validated['periods']), 2),
            'periods'               => (int) $validated['periods'],
            'periods_remaining'     => (int) $validated['periods'],
            'status'                => 'pending',
            'remarks'               => $validated['reason'] ?? null,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return response()->json([
            'message' => 'Loan request submitted for approval.',
            'data'    => ['id' => app('hashids')->encode((int) $id)],
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
            ]]);
        }

        $rows = OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->with('approver:id,name')
            ->orderByDesc('date')
            ->limit(60)
            ->get();

        $map = fn (OvertimeRequest $r) => [
            'id'               => $r->hash_id,
            'date'             => optional($r->date)->toDateString(),
            'hours_requested'  => (string) $r->hours_requested,
            'reason'           => $r->reason,
            'status'           => $r->status?->value,
            'rejection_reason' => $r->rejection_reason,
            'approver'         => $r->approver?->name,
            'created_at'       => optional($r->created_at)->toIso8601String(),
        ];

        $pending = $rows->where('status', OvertimeStatus::Pending)->map($map)->values()->all();
        $history = $rows->whereNotIn('status', [OvertimeStatus::Pending])->map($map)->values()->all();

        return response()->json([
            'data' => [
                'pending'      => $pending,
                'history'      => $history,
                'todays_shift' => $this->todaysShift($employee),
                'hourly_rate'  => $this->estimatedHourlyRate($employee),
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

        $validated = $request->validate([
            'date'            => ['required', 'date', 'after_or_equal:'.now()->toDateString(), 'before_or_equal:'.now()->addDays(30)->toDateString()],
            'hours_requested' => ['required', 'numeric', 'min:0.5', 'max:4'],
            'reason'          => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'date.after_or_equal'  => 'Overtime can only be requested for today or a future date.',
            'date.before_or_equal' => 'Overtime cannot be requested more than 30 days ahead.',
            'hours_requested.max'  => 'Overtime cannot exceed 4 hours per day.',
            'reason.min'           => 'Please provide a meaningful reason (at least 5 characters).',
        ]);

        $ot = $this->overtime->create([
            'employee_id'     => $employee->id,
            'date'            => $validated['date'],
            'hours_requested' => $validated['hours_requested'],
            'reason'          => trim($validated['reason']),
        ]);

        return response()->json([
            'message' => 'Overtime request submitted for Dept Head approval.',
            'data'    => ['id' => $ot->hash_id, 'status' => $ot->status?->value],
        ], 201);
    }

    public function profile(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        return response()->json([
            'data' => [
                'id'          => $employee->hash_id,
                'employee_no' => $employee->employee_no,
                'full_name'   => $employee->full_name,
                'first_name'  => $employee->first_name,
                'middle_name' => $employee->middle_name,
                'last_name'   => $employee->last_name,
                'department'  => $employee->department?->name,
                'position'    => $employee->position?->title,
                'date_hired'  => optional($employee->date_hired)->toDateString(),
                'employment_type' => $employee->employment_type?->value,
                'photo_path'  => $employee->photo_path,
                // Editable
                'mobile_number' => $employee->mobile_number,
                'email'         => $employee->email,
                'street_address' => $employee->street_address,
                'barangay'       => $employee->barangay,
                'city'           => $employee->city,
                'province'       => $employee->province,
                'zip_code'       => $employee->zip_code,
                'emergency_contact_name'     => $employee->emergency_contact_name,
                'emergency_contact_relation' => $employee->emergency_contact_relation,
                'emergency_contact_phone'    => $employee->emergency_contact_phone,
                // Bank (account masked — last 4 only; change needs HR + Finance).
                'bank_name'           => $employee->bank_name,
                'bank_account_last4'  => $this->last4($employee->bank_account_no),
                // Government IDs are masked (last 4) — never returned in full.
                'sss_no_last4'        => $this->last4($employee->sss_no),
                'philhealth_no_last4' => $this->last4($employee->philhealth_no),
                'pagibig_no_last4'    => $this->last4($employee->pagibig_no),
                'tin_last4'           => $this->last4($employee->tin),
            ],
        ]);
    }

    public function requestProfileUpdate(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $validated = $request->validate([
            'changes' => ['required', 'array'],
            'note'    => ['nullable', 'string', 'max:500'],
        ]);

        $req = $this->profileUpdates->submit(
            $employee,
            $request->user(),
            $validated['changes'],
            $validated['note'] ?? null,
        );

        return response()->json([
            'message' => 'Profile update request submitted for HR review.',
            'data'    => ['id' => $req->hash_id, 'status' => $req->status],
        ], 201);
    }

    public function profileUpdateRequests(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $rows = $this->profileUpdates->listForEmployee($employee);
        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id'         => $r->hash_id,
                'status'     => $r->status,
                'changes'    => $r->changes,
                'note'       => $r->note,
                'reviewed_at'=> optional($r->reviewed_at)->toIso8601String(),
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
            && \App\Modules\Payroll\Models\Payroll::query()
                ->where('employee_id', $employee->id)
                ->whereHas('period', fn ($q) => $q->whereYear('period_start', $lastYear))
                ->exists();

        return response()->json([
            'data' => [
                'certificates' => [
                    ['key' => 'employment',  'label' => 'Certificate of Employment',          'available' => true,  'note' => 'Generated instantly'],
                    ['key' => 'sss',         'label' => 'Certificate of SSS Contributions',    'available' => true,  'note' => "Year {$thisYear}"],
                    ['key' => 'philhealth',  'label' => 'Certificate of PhilHealth Contributions', 'available' => true, 'note' => "Year {$thisYear}"],
                    ['key' => 'pagibig',     'label' => 'Certificate of Pag-IBIG Contributions', 'available' => true, 'note' => "Year {$thisYear}"],
                    ['key' => 'bir_2316',    'label' => 'BIR 2316 (Compensation)',             'available' => $bir2316Available, 'note' => $bir2316Available ? "Year {$lastYear}" : 'Available after year-end closing'],
                ],
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

    private function greeting(): string
    {
        $hour = (int) now()->format('G');
        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default    => 'Good evening',
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
            ->where('a.effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('a.effective_until')
                  ->orWhere('a.effective_until', '>=', $today);
            })
            ->orderByDesc('a.effective_from')
            ->select('s.name', 's.time_in', 's.time_out')
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
        $daily = $employee->pay_type?->value === 'monthly'
            ? ((float) ($employee->basic_monthly_salary ?? 0)) / 22.0
            : (float) ($employee->daily_rate ?? 0);

        if ($daily <= 0) {
            return null;
        }

        return number_format($daily / 8.0, 2, '.', '');
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
