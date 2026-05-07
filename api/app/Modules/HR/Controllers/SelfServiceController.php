<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U3 — Employee self-service endpoints. The current user must be linked to
 * an employee row; every endpoint scopes data to that employee only.
 */
class SelfServiceController
{
    public function __construct(
        private readonly ProfileUpdateRequestService $profileUpdates,
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

        // Today's shift (best-effort — table may not exist in all envs).
        $shift = null;
        if (Schema::hasTable('employee_shift_assignments') && Schema::hasTable('shifts')) {
            $row = DB::table('employee_shift_assignments as a')
                ->join('shifts as s', 's.id', '=', 'a.shift_id')
                ->where('a.employee_id', $employee->id)
                ->where(function ($q) use ($today) {
                    $q->whereNull('a.effective_until')
                      ->orWhere('a.effective_until', '>=', $today);
                })
                ->where('a.effective_from', '<=', $today)
                ->orderByDesc('a.effective_from')
                ->select('s.name', 's.time_in', 's.time_out')
                ->first();
            if ($row) {
                $shift = ['name' => $row->name, 'time_in' => $row->time_in, 'time_out' => $row->time_out];
            }
        }

        // Leave balances for current year.
        $year = (int) now()->format('Y');
        $balances = [];
        if (Schema::hasTable('employee_leave_balances') && Schema::hasTable('leave_types')) {
            $balances = DB::table('employee_leave_balances as b')
                ->join('leave_types as t', 't.id', '=', 'b.leave_type_id')
                ->where('b.employee_id', $employee->id)
                ->where('b.year', $year)
                ->select('t.code', 't.name', 'b.total_credits', 'b.used', 'b.remaining')
                ->get()
                ->map(fn ($r) => [
                    'code'      => $r->code,
                    'name'      => $r->name,
                    'total'     => (float) $r->total_credits,
                    'used'      => (float) $r->used,
                    'remaining' => (float) $r->remaining,
                ])
                ->all();
        }

        // Pending requests count (leave + loan).
        $pending = 0;
        if (Schema::hasTable('leave_requests')) {
            $pending += (int) DB::table('leave_requests')
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count();
        }
        if (Schema::hasTable('employee_loans')) {
            $pending += (int) DB::table('employee_loans')
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count();
        }

        // Latest payslip summary.
        $latestPayslip = null;
        if (Schema::hasTable('payrolls') && Schema::hasTable('payroll_periods')) {
            $row = DB::table('payrolls as p')
                ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
                ->where('p.employee_id', $employee->id)
                ->where('pp.status', 'finalized')
                ->orderByDesc('pp.period_end')
                ->select('p.id', 'p.gross_pay', 'p.net_pay', 'pp.period_start', 'pp.period_end')
                ->first();
            if ($row) {
                $latestPayslip = [
                    'id'           => app('hashids')->encode((int) $row->id),
                    'period_start' => (string) $row->period_start,
                    'period_end'   => (string) $row->period_end,
                    'gross_pay'    => (string) $row->gross_pay,
                    'net_pay'      => (string) $row->net_pay,
                ];
            }
        }

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
                'todays_shift'    => $shift,
                'leave_balances'  => $balances,
                'pending_count'   => $pending,
                'latest_payslip'  => $latestPayslip,
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

    private function greeting(): string
    {
        $hour = (int) now()->format('G');
        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default    => 'Good evening',
        };
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
