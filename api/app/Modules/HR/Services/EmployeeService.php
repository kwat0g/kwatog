<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentChangeType;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {}

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $query = Employee::query()->with(['department', 'position']);

        // Row-level filtering. Admin/HR see all. Department Head sees only their dept.
        // Plain employees see only themselves.
        if ($user) {
            $roleSlug = $user->role?->slug;
            $isAdmin = $roleSlug === 'system_admin';
            $isHr = $user->hasPermission('hr.employees.view_sensitive') || $user->hasPermission('hr.employees.create');
            if (! $isAdmin && ! $isHr) {
                $employeeId = $user->employee_id;
                if ($roleSlug === 'department_head') {
                    $deptId = Employee::query()->whereKey($employeeId)->value('department_id');
                    if ($deptId) $query->where('department_id', $deptId);
                } else {
                    $query->whereKey($employeeId);
                }
            }
        }

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('employee_no', 'ilike', "%{$term}%")
                  ->orWhere('first_name', 'ilike', "%{$term}%")
                  ->orWhere('middle_name', 'ilike', "%{$term}%")
                  ->orWhere('last_name', 'ilike', "%{$term}%");
            });
        }
        if (!empty($filters['department_id'])) {
            $deptId = \App\Common\Support\HashIdFilter::decode(
                $filters['department_id'], \App\Modules\HR\Models\Department::class,
            );
            if ($deptId) $query->where('department_id', $deptId);
        }
        if (!empty($filters['position_id'])) {
            $posId = \App\Common\Support\HashIdFilter::decode(
                $filters['position_id'], \App\Modules\HR\Models\Position::class,
            );
            if ($posId) $query->where('position_id', $posId);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['employment_type'])) {
            $query->where('employment_type', $filters['employment_type']);
        }
        if (!empty($filters['pay_type'])) {
            $query->where('pay_type', $filters['pay_type']);
        }

        $sort = $filters['sort'] ?? 'employee_no';
        $dir  = $filters['direction'] ?? 'desc';
        $allowed = ['employee_no', 'first_name', 'last_name', 'date_hired', 'status', 'created_at'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        return $query->paginate($perPage);
    }

    public function show(Employee $employee): Employee
    {
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

            /** @var Employee $employee */
            $employee = Employee::create($data);

            EmploymentHistory::create([
                'employee_id'    => $employee->id,
                'change_type'    => EmploymentChangeType::Hired->value,
                'to_value'       => [
                    'department_id' => $employee->department_id,
                    'position_id'   => $employee->position_id,
                    'employment_type' => $employee->employment_type instanceof \BackedEnum ? $employee->employment_type->value : $employee->employment_type,
                    'pay_type'      => $employee->pay_type instanceof \BackedEnum ? $employee->pay_type->value : $employee->pay_type,
                    'salary'        => $employee->basic_monthly_salary ?? $employee->daily_rate,
                ],
                'effective_date' => $employee->date_hired,
                'created_at'     => now(),
            ]);

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
                                'used'          => 0,
                                'remaining'     => $lt->default_balance,
                                'created_at'    => now(),
                                'updated_at'    => now(),
                            ],
                        );
                    });
            }

            return $employee->load(['department', 'position']);
        });
    }

    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $original = $employee->only([
                'department_id', 'position_id', 'basic_monthly_salary', 'daily_rate', 'employment_type', 'pay_type',
            ]);

            $employee->update($data);
            $employee->refresh();

            $changes = [];
            if (array_key_exists('department_id', $data) && (int) $original['department_id'] !== (int) $employee->department_id) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::Transferred->value,
                    'from_value'  => ['department_id' => $original['department_id']],
                    'to_value'    => ['department_id' => $employee->department_id],
                ];
            }
            if (array_key_exists('position_id', $data) && (int) $original['position_id'] !== (int) $employee->position_id) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::Promoted->value,
                    'from_value'  => ['position_id' => $original['position_id']],
                    'to_value'    => ['position_id' => $employee->position_id],
                ];
            }
            if (
                (array_key_exists('basic_monthly_salary', $data) && (string) $original['basic_monthly_salary'] !== (string) $employee->basic_monthly_salary)
                || (array_key_exists('daily_rate', $data) && (string) $original['daily_rate'] !== (string) $employee->daily_rate)
            ) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::SalaryAdjusted->value,
                    'from_value'  => [
                        'basic_monthly_salary' => $original['basic_monthly_salary'],
                        'daily_rate'           => $original['daily_rate'],
                    ],
                    'to_value'    => [
                        'basic_monthly_salary' => $employee->basic_monthly_salary,
                        'daily_rate'           => $employee->daily_rate,
                    ],
                ];
            }
            if (array_key_exists('date_regularized', $data) && $employee->date_regularized) {
                $changes[] = [
                    'change_type' => EmploymentChangeType::Regularized->value,
                    'from_value'  => null,
                    'to_value'    => ['date_regularized' => $employee->date_regularized?->toDateString()],
                ];
            }

            foreach ($changes as $c) {
                EmploymentHistory::create([
                    'employee_id'    => $employee->id,
                    'change_type'    => $c['change_type'],
                    'from_value'     => $c['from_value'],
                    'to_value'       => $c['to_value'],
                    'effective_date' => now()->toDateString(),
                    'approved_by'    => optional(request()->user())->id,
                    'created_at'     => now(),
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
                'resigned'       => EmployeeStatus::Resigned,
                'terminated'     => EmployeeStatus::Terminated,
                'retired'        => EmployeeStatus::Retired,
                'end_of_contract'=> EmployeeStatus::Resigned,
            ];
            $status = $statusMap[$reason] ?? EmployeeStatus::Resigned;

            $employee->update(['status' => $status->value]);

            EmploymentHistory::create([
                'employee_id'    => $employee->id,
                'change_type'    => EmploymentChangeType::Separated->value,
                'to_value'       => [
                    'separation_reason' => $reason,
                    'separation_date'   => $data['separation_date'],
                    'remarks'           => $data['remarks'] ?? null,
                ],
                'effective_date' => $data['separation_date'],
                'remarks'        => $data['remarks'] ?? null,
                'approved_by'    => optional(request()->user())->id,
                'created_at'     => now(),
            ]);

            return $employee->fresh(['department', 'position']);
        });
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }
}
