<?php

declare(strict_types=1);

namespace App\Modules\HR\Exports;

use App\Common\Exports\BaseModuleExport;
use App\Common\Services\Export\ExportColumnRegistry;
use App\Common\Support\DepartmentScope;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Series E (Task E2) — Employee master list export.
 *
 * Columns are registered via ExportColumnRegistry; static call below runs
 * once per process so the SPA's ColumnSelectorModal sees them in
 * `GET /api/v1/exports/hr.employees/columns`.
 */
class EmployeeMasterExport extends BaseModuleExport
{
    public const MODULE = 'hr.employees';

    /**
     * Sanitize caller-selected columns at the module boundary. The generic
     * export controller accepts user-supplied column names, so relying on the
     * selector UI would leave a direct download URL able to request arbitrary
     * model attributes.
     *
     * @param array<int, string> $columns
     * @param array<string, mixed> $filters
     */
    public function __construct(array $columns, array $filters = [], ?User $actor = null)
    {
        $actor ??= auth()->user();
        $this->actor = $actor instanceof User ? $actor : null;

        parent::__construct($columns, $filters, $this->actor);
    }

    public function module(): string
    {
        return self::MODULE;
    }

    public function collection(): Collection
    {
        $query = Employee::query()->with(['department', 'position']);

        if ($this->actor) {
            DepartmentScope::apply(
                $query,
                $this->actor,
                viewAllPermission: 'hr.employees.view_sensitive',
                departmentPermission: 'hr.employees.view',
                deptColumn: 'department_id',
                selfColumn: 'id',
                selfId: $this->actor->employee_id,
            );
        }

        if (! empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }
        if (! empty($this->filters['department_id'])) {
            $departmentId = HashIdFilter::decode($this->filters['department_id'], Department::class);
            if ($departmentId === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('department_id', $departmentId);
            }
        }
        if (! empty($this->filters['pay_type'])) {
            $query->where('pay_type', $this->filters['pay_type']);
        }

        return $query->orderBy('employee_no')->get();
    }

    /**
     * Idempotent column registration. Called from ModuleServiceProvider so
     * the registry is populated whenever the app boots.
     */
    public static function registerColumns(): void
    {
        ExportColumnRegistry::registerModule(self::MODULE, self::class, 'hr.employees.export', [
            'employee_no' => [
                'label'   => 'Employee No.',
                'default' => true,
                'resolver' => fn (Employee $e) => $e->employee_no,
            ],
            'full_name' => [
                'label'   => 'Name',
                'default' => true,
                'resolver' => fn (Employee $e) => $e->full_name,
            ],
            'department' => [
                'label'   => 'Department',
                'default' => true,
                'resolver' => fn (Employee $e) => $e->department?->name,
            ],
            'position' => [
                'label'   => 'Position',
                'default' => true,
                'resolver' => fn (Employee $e) => $e->position?->title,
            ],
            'employment_type' => [
                'label'   => 'Employment Type',
                'default' => true,
                'resolver' => fn (Employee $e) => $e->employment_type,
            ],
            'pay_type' => [
                'label'   => 'Pay Type',
                'default' => true,
                'resolver' => fn (Employee $e) => $e->pay_type,
            ],
            'monthly_salary' => [
                'label'   => 'Monthly Salary',
                'default' => false,
                'format'  => 'money',
                'permission' => 'hr.employees.view_sensitive',
                'resolver' => fn (Employee $e) => $e->basic_monthly_salary !== null
                    ? Money::round2((string) $e->basic_monthly_salary)
                    : null,
            ],
            'semi_monthly_rate' => [
                'label'   => 'Semi-monthly Rate',
                'default' => false,
                'format'  => 'money',
                'permission' => 'hr.employees.view_sensitive',
                'resolver' => fn (Employee $e) => $e->semi_monthly_rate !== null
                    ? Money::round2((string) $e->semi_monthly_rate)
                    : null,
            ],
            'date_hired' => [
                'label'   => 'Date Hired',
                'default' => true,
                'format'  => 'date',
                'resolver' => fn (Employee $e) => optional($e->date_hired)->format('M d, Y'),
            ],
            'status' => [
                'label'   => 'Status',
                'default' => true,
                'resolver' => fn (Employee $e) => $e->status?->label()
                    ?? ucwords(str_replace('_', ' ', (string) $e->getRawOriginal('status'))),
            ],
            'email' => [
                'label'   => 'Email',
                'default' => false,
                'resolver' => fn (Employee $e) => $e->email,
            ],
            'mobile_number' => [
                'label'   => 'Mobile Number',
                'default' => false,
                'resolver' => fn (Employee $e) => $e->mobile_number,
            ],
        ], ['status', 'department_id', 'pay_type']);
    }
}
