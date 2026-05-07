<?php

declare(strict_types=1);

namespace App\Modules\HR\Exports;

use App\Common\Exports\BaseModuleExport;
use App\Common\Services\Export\ExportColumnRegistry;
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

    public function module(): string
    {
        return self::MODULE;
    }

    public function collection(): Collection
    {
        $query = Employee::query()->with(['department', 'position']);

        if (! empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }
        if (! empty($this->filters['department_id'])) {
            $query->where('department_id', $this->filters['department_id']);
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
        ExportColumnRegistry::register(self::MODULE, [
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
                'label'   => 'Monthly Salary (PHP)',
                'default' => false,
                'format'  => 'money',
                'resolver' => fn (Employee $e) => $e->basic_monthly_salary !== null
                    ? (float) $e->basic_monthly_salary
                    : null,
            ],
            'daily_rate' => [
                'label'   => 'Daily Rate (PHP)',
                'default' => false,
                'format'  => 'money',
                'resolver' => fn (Employee $e) => $e->daily_rate !== null
                    ? (float) $e->daily_rate
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
                'resolver' => fn (Employee $e) => ucwords(str_replace('_', ' ', (string) $e->status)),
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
        ]);
    }
}
