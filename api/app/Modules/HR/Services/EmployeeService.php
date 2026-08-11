<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Support\DepartmentScope;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentChangeType;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentHistory;
use App\Modules\HR\Models\Position;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly OnboardingService $onboarding,
    ) {}

    /**
     * Scope + non-status filters, shared by list() and statusCounts().
     *
     * Extracted so the KPI tiles above the employee list are computed from the
     * SAME row set the table paginates. They used to be counted client-side
     * over whichever 25 rows happened to be on screen, so "Active 18" was the
     * page, not the company.
     *
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters, ?User $user = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = Employee::query();

        // REC-11 — centralized, permission-driven row-level scope. Tiers:
        // hr.employees.view_sensitive (HR/admin) → all; hr.employees.view
        // (dept head) → own department + self; neither (plain employee) →
        // self only. Resolves from grants + the user's linked employee, NOT
        // role-slug equality, so a forgotten block can never leak a dept's rows.
        if ($user) {
            DepartmentScope::apply(
                $query,
                $user,
                viewAllPermission: 'hr.employees.view_sensitive',
                departmentPermission: 'hr.employees.view',
                deptColumn: 'department_id',
                selfColumn: 'id',
                selfId: $user->employee_id,
            );
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('employee_no', 'ilike', "%{$term}%")
                    ->orWhere('first_name', 'ilike', "%{$term}%")
                    ->orWhere('middle_name', 'ilike', "%{$term}%")
                    ->orWhere('last_name', 'ilike', "%{$term}%");
            });
        }
        if (! empty($filters['department_id'])) {
            $deptId = HashIdFilter::decode(
                $filters['department_id'], Department::class,
            );
            if ($deptId) {
                $query->where('department_id', $deptId);
            }
        }
        if (! empty($filters['position_id'])) {
            $posId = HashIdFilter::decode(
                $filters['position_id'], Position::class,
            );
            if ($posId) {
                $query->where('position_id', $posId);
            }
        }
        if (! empty($filters['employment_type'])) {
            $query->where('employment_type', $filters['employment_type']);
        }
        if (! empty($filters['pay_type'])) {
            $query->where('pay_type', $filters['pay_type']);
        }

        return $query;
    }

    /**
     * Headcount per status across the whole filtered set — NOT the current page.
     *
     * The `status` filter is deliberately ignored: these counts back the tiles
     * that navigate TO a status, so they have to describe every status under
     * the other active filters. Applying `status` here would collapse the
     * tiles to "n, 0, 0, 0" the moment one was clicked.
     *
     * @param  array<string, mixed>  $filters
     * @return array{counts: array<string, int>, total: int}
     */
    public function statusCounts(array $filters, ?User $user = null): array
    {
        $rows = $this->baseQuery($filters, $user)
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // Zero-fill every case so the UI never has to guess whether a missing
        // key means "none" or "not computed".
        $counts = [];
        foreach (EmployeeStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return ['counts' => $counts, 'total' => array_sum($counts)];
    }

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        // Include the linked account so resources can populate an empty legacy
        // employee email from the authoritative login record without issuing
        // per-row lazy queries.
        $query = $this->baseQuery($filters, $user)
            ->with(['department', 'position', 'user:id,employee_id,email']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sort = $filters['sort'] ?? 'employee_no';
        $dir = $filters['direction'] ?? 'desc';
        $allowed = ['employee_no', 'first_name', 'last_name', 'date_hired', 'status', 'created_at'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    public function show(Employee $employee, ?User $user = null): Employee
    {
        if ($user) {
            $query = Employee::query()->whereKey($employee->getKey());
            DepartmentScope::apply(
                $query,
                $user,
                viewAllPermission: 'hr.employees.view_sensitive',
                departmentPermission: 'hr.employees.view',
                deptColumn: 'department_id',
                selfColumn: 'id',
                selfId: $user->employee_id,
            );
            $employee = $query->firstOrFail();
        }

        return $employee->load([
            'department', 'position', 'user',
            'employmentHistory.approver',
            'documents', 'property',
        ]);
    }

    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $data['employee_no'] = $this->sequences->generate('employee');

            $shiftId = ! empty($data['shift_id']) ? (int) $data['shift_id'] : null;
            unset($data['shift_id']);

            /** @var Employee $employee */
            $employee = Employee::create($data);

            EmploymentHistory::create([
                'employee_id' => $employee->id,
                'change_type' => EmploymentChangeType::Hired->value,
                'to_value' => [
                    'department_id' => $employee->department_id,
                    'position_id' => $employee->position_id,
                    'employment_type' => $employee->employment_type instanceof \BackedEnum ? $employee->employment_type->value : $employee->employment_type,
                    'pay_type' => $employee->pay_type instanceof \BackedEnum ? $employee->pay_type->value : $employee->pay_type,
                    'salary' => $employee->basic_monthly_salary ?? $employee->semi_monthly_rate,
                ],
                'effective_date' => $employee->date_hired,
                'created_at' => now(),
            ]);

            // Auto-assign shift: use explicit shift_id from data, or fall back to default shift.
            if (! $shiftId && Schema::hasTable('shifts')) {
                $defaultShift = DB::table('shifts')->where('is_default', true)->value('id');
                if ($defaultShift) {
                    $shiftId = (int) $defaultShift;
                }
            }
            if ($shiftId && Schema::hasTable('employee_shift_assignments')) {
                DB::table('employee_shift_assignments')->insert([
                    'employee_id'    => $employee->id,
                    'shift_id'       => $shiftId,
                    'effective_date' => $employee->date_hired?->toDateString() ?? now()->toDateString(),
                    'created_at'     => now(),
                ]);
            }

            // U4 — initialize onboarding tracker (auto-completes profile +
            // leave-balance steps inside this same transaction).
            $this->onboarding->initialize($employee);

            // Seed default leave balances if leave module is loaded.
            if (Schema::hasTable('leave_types') && Schema::hasTable('employee_leave_balances')) {
                $year = (int) now()->format('Y');
                DB::table('leave_types')
                    ->where('is_active', true)
                    ->get()
                    ->each(function ($lt) use ($employee, $year) {
                        DB::table('employee_leave_balances')->updateOrInsert(
                            ['employee_id' => $employee->id, 'leave_type_id' => $lt->id, 'year' => $year],
                            [
                                'total_credits' => $lt->default_balance,
                                'used' => 0,
                                'remaining' => $lt->default_balance,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ],
                        );
                    });
            }

            // Recompute once at the very end so derived steps (gov ids, banking)
            // pick up any complete-on-create info.
            $this->onboarding->recompute($employee);

            $fresh = $employee->load(['department', 'position']);
            // Durable hire event. The leave-balance and account-provisioning
            // listeners can be replayed after a worker outage without losing
            // the employee-created transition.
            app(OutboxService::class)->recordForChain(
                new EmployeeCreated($fresh),
                $fresh,
                'h2r',
                'employee',
                'created',
            );

            return $fresh;
        });
    }

    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            // REC-03 — pay can ONLY change through the maker-checker SalaryAdjustment
            // gate (SalaryAdjustmentService). Strip salary fields here so a direct
            // employee edit can never bypass approval, even if a caller supplies them.
            unset($data['basic_monthly_salary'], $data['semi_monthly_rate']);

            $original = $employee->only([
                'department_id', 'position_id', 'basic_monthly_salary', 'semi_monthly_rate', 'employment_type', 'pay_type',
            ]);

            $employee->update($data);
            $employee->refresh();

            $changes = [];
            if (array_key_exists('department_id', $data) && (int) $original['department_id'] !== (int) $employee->department_id) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::Transferred->value,
                    'from_value' => ['department_id' => $original['department_id']],
                    'to_value' => ['department_id' => $employee->department_id],
                ];
            }
            if (array_key_exists('position_id', $data) && (int) $original['position_id'] !== (int) $employee->position_id) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::Promoted->value,
                    'from_value' => ['position_id' => $original['position_id']],
                    'to_value' => ['position_id' => $employee->position_id],
                ];
            }
            if (
                (array_key_exists('basic_monthly_salary', $data) && (string) $original['basic_monthly_salary'] !== (string) $employee->basic_monthly_salary)
                || (array_key_exists('semi_monthly_rate', $data) && (string) $original['semi_monthly_rate'] !== (string) $employee->semi_monthly_rate)
            ) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::SalaryAdjusted->value,
                    'from_value' => [
                        'basic_monthly_salary' => $original['basic_monthly_salary'],
                        'semi_monthly_rate' => $original['semi_monthly_rate'],
                    ],
                    'to_value' => [
                        'basic_monthly_salary' => $employee->basic_monthly_salary,
                        'semi_monthly_rate' => $employee->semi_monthly_rate,
                    ],
                ];
            }
            if (array_key_exists('date_regularized', $data) && $employee->date_regularized) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::Regularized->value,
                    'from_value' => null,
                    'to_value' => ['date_regularized' => $employee->date_regularized?->toDateString()],
                ];
            }

            foreach ($changes as $c) {
                EmploymentHistory::create([
                    'employee_id' => $employee->id,
                    'change_type' => $c['change_type'],
                    'from_value' => $c['from_value'],
                    'to_value' => $c['to_value'],
                    'effective_date' => now()->toDateString(),
                    'approved_by' => optional(request()->user())->id,
                    'created_at' => now(),
                ]);
            }

            return $employee->load(['department', 'position']);
        });
    }

    public function separate(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $reason = $data['separation_reason'];
            $statusMap = [
                'resigned' => EmployeeStatus::Resigned,
                'terminated' => EmployeeStatus::Terminated,
                'retired' => EmployeeStatus::Retired,
                'end_of_contract' => EmployeeStatus::Resigned,
            ];
            $status = $statusMap[$reason] ?? EmployeeStatus::Resigned;

            $employee->update(['status' => $status->value]);

            EmploymentHistory::create([
                'employee_id' => $employee->id,
                'change_type' => EmploymentChangeType::Separated->value,
                'to_value' => [
                    'separation_reason' => $reason,
                    'separation_date' => $data['separation_date'],
                    'remarks' => $data['remarks'] ?? null,
                ],
                'effective_date' => $data['separation_date'],
                'remarks' => $data['remarks'] ?? null,
                'approved_by' => optional(request()->user())->id,
                'created_at' => now(),
            ]);

            return $employee->fresh(['department', 'position']);
        });
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }
}
