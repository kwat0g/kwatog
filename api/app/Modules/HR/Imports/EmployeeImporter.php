<?php

declare(strict_types=1);

namespace App\Modules\HR\Imports;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\Import\EntityImporter;
use App\Modules\HR\Enums\CivilStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * REC-03 — employee master importer (the headline migration case: 200+ staff).
 *
 * Required CSV columns: first_name, last_name, birth_date, gender, civil_status,
 * department, position, employment_type, pay_type, date_hired.
 * Salary: basic_monthly_salary (pay_type=monthly) OR daily_rate (pay_type=daily).
 * Optional: employee_no (preserve legacy id — else generated), middle_name,
 * suffix, mobile_number, email, street_address, city, province, sss_no,
 * philhealth_no, pagibig_no, tin, bank_name, bank_account_no, date_regularized.
 *
 * department resolves by code then name; position by title (created within the
 * department if absent). Government IDs + bank account are stored via the
 * model's encrypted casts.
 */
class EmployeeImporter implements EntityImporter
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    public function key(): string
    {
        return 'employees';
    }

    public function requiredColumns(): array
    {
        return [
            'first_name', 'last_name', 'birth_date', 'gender', 'civil_status',
            'department', 'position', 'employment_type', 'pay_type', 'date_hired',
        ];
    }

    public function importRow(array $row): Model
    {
        $first = trim($row['first_name'] ?? '');
        $last  = trim($row['last_name'] ?? '');
        if ($first === '' || $last === '') {
            throw new RuntimeException('first_name and last_name are required.');
        }

        $gender = strtolower(trim($row['gender'] ?? ''));
        $this->assertEnum($gender, Gender::values(), 'gender');
        $civil = strtolower(trim($row['civil_status'] ?? ''));
        $this->assertEnum($civil, CivilStatus::values(), 'civil_status');
        $empType = strtolower(trim($row['employment_type'] ?? ''));
        $this->assertEnum($empType, EmploymentType::values(), 'employment_type');
        $payType = strtolower(trim($row['pay_type'] ?? ''));
        $this->assertEnum($payType, PayType::values(), 'pay_type');

        $dept = $this->resolveDepartment(trim($row['department'] ?? ''));
        $position = $this->resolvePosition(trim($row['position'] ?? ''), $dept);

        $monthly = trim($row['basic_monthly_salary'] ?? '');
        $daily   = trim($row['daily_rate'] ?? '');
        if ($payType === PayType::Monthly->value && $monthly === '') {
            throw new RuntimeException('basic_monthly_salary is required for a monthly-paid employee.');
        }
        if ($payType === PayType::Daily->value && $daily === '') {
            throw new RuntimeException('daily_rate is required for a daily-paid employee.');
        }

        $employeeNo = trim($row['employee_no'] ?? '');
        if ($employeeNo === '') {
            $employeeNo = $this->sequences->generate('employee');
        } elseif (Employee::query()->where('employee_no', $employeeNo)->exists()) {
            throw new RuntimeException("employee_no '{$employeeNo}' already exists.");
        }

        return Employee::create([
            'employee_no'     => $employeeNo,
            'first_name'      => $first,
            'middle_name'     => trim($row['middle_name'] ?? '') ?: null,
            'last_name'       => $last,
            'suffix'          => trim($row['suffix'] ?? '') ?: null,
            'birth_date'      => $this->date($row['birth_date'] ?? '', 'birth_date'),
            'gender'          => $gender,
            'civil_status'    => $civil,
            'nationality'     => trim($row['nationality'] ?? '') ?: 'Filipino',
            'street_address'  => trim($row['street_address'] ?? '') ?: null,
            'city'            => trim($row['city'] ?? '') ?: null,
            'province'        => trim($row['province'] ?? '') ?: null,
            'mobile_number'   => trim($row['mobile_number'] ?? '') ?: null,
            'email'           => trim($row['email'] ?? '') ?: null,
            'sss_no'          => trim($row['sss_no'] ?? '') ?: null,
            'philhealth_no'   => trim($row['philhealth_no'] ?? '') ?: null,
            'pagibig_no'      => trim($row['pagibig_no'] ?? '') ?: null,
            'tin'             => trim($row['tin'] ?? '') ?: null,
            'department_id'   => $dept->id,
            'position_id'     => $position->id,
            'employment_type' => $empType,
            'pay_type'        => $payType,
            'date_hired'      => $this->date($row['date_hired'] ?? '', 'date_hired'),
            'date_regularized' => trim($row['date_regularized'] ?? '') !== ''
                ? $this->date($row['date_regularized'], 'date_regularized') : null,
            'basic_monthly_salary' => $monthly !== '' ? $monthly : null,
            'daily_rate'      => $daily !== '' ? $daily : null,
            'bank_name'       => trim($row['bank_name'] ?? '') ?: null,
            'bank_account_no' => trim($row['bank_account_no'] ?? '') ?: null,
            'status'          => 'active',
        ]);
    }

    private function resolveDepartment(string $ref): Department
    {
        if ($ref === '') {
            throw new RuntimeException('department is required.');
        }
        $dept = Department::query()->where('code', $ref)->orWhere('name', $ref)->first();
        if (! $dept) {
            throw new RuntimeException("department '{$ref}' not found (import departments first).");
        }
        return $dept;
    }

    private function resolvePosition(string $title, Department $dept): Position
    {
        if ($title === '') {
            throw new RuntimeException('position is required.');
        }
        return Position::firstOrCreate(
            ['title' => $title, 'department_id' => $dept->id],
        );
    }

    /** @param array<int,string> $allowed */
    private function assertEnum(string $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException("Invalid {$field} '{$value}'. Expected one of: ".implode(', ', $allowed));
        }
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new BusinessRuleException("{$field} is required.");
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new BusinessRuleException("Invalid {$field} date '{$value}'.");
        }
    }
}
