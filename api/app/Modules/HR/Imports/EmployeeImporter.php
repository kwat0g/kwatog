<?php

declare(strict_types=1);

namespace App\Modules\HR\Imports;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\Import\EntityImporter;
use App\Common\Support\PhFormat;
use App\Modules\HR\Enums\CivilStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentHistory;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\EmployeeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * REC-03 — employee master importer (the headline migration case: 200+ staff).
 *
 * Required CSV columns: first_name, last_name, birth_date, gender, civil_status,
 * department, position, employment_type, pay_type, date_hired.
 * Salary: basic_monthly_salary (pay_type=monthly) OR semi_monthly_rate (pay_type=semi_monthly).
 * Optional: employee_no (preserve legacy id — else generated), middle_name,
 * suffix, mobile_number, email, street_address, city, province, sss_no,
 * philhealth_no, pagibig_no, tin, bank_name, bank_account_no, date_regularized,
 * status.
 *
 * department resolves by code then name; position by title (created within the
 * department if absent). Government IDs + bank account are stored via the
 * model's encrypted casts.
 */
class EmployeeImporter implements EntityImporter
{
    public function __construct(private readonly EmployeeService $employees) {}

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
        $this->assertText($first, 'first_name');
        $this->assertText($last, 'last_name');

        $gender = strtolower(trim($row['gender'] ?? ''));
        $this->assertEnum($gender, Gender::values(), 'gender');
        $civil = strtolower(trim($row['civil_status'] ?? ''));
        $this->assertEnum($civil, CivilStatus::values(), 'civil_status');
        $empType = strtolower(trim($row['employment_type'] ?? ''));
        $this->assertEnum($empType, EmploymentType::values(), 'employment_type');
        $payType = strtolower(trim($row['pay_type'] ?? ''));
        $this->assertEnum($payType, PayType::values(), 'pay_type');

        $dept = $this->resolveDepartment(trim($row['department'] ?? ''));
        [$position, $positionCreated] = $this->resolvePosition(trim($row['position'] ?? ''), $dept);

        $monthly = $this->money($row['basic_monthly_salary'] ?? '', 'basic_monthly_salary');
        $semiMonthly = $this->money($row['semi_monthly_rate'] ?? '', 'semi_monthly_rate');
        if ($payType === PayType::Monthly->value && $monthly === null) {
            throw new RuntimeException('basic_monthly_salary is required for a monthly-paid employee.');
        }
        if ($payType === PayType::SemiMonthly->value && $semiMonthly === null) {
            throw new RuntimeException('semi_monthly_rate is required for a semi-monthly-paid employee.');
        }

        $employeeNo = trim($row['employee_no'] ?? '');
        if ($employeeNo !== '' && Employee::withTrashed()->where('employee_no', $employeeNo)->exists()) {
            throw new RuntimeException("employee_no '{$employeeNo}' already exists.");
        }

        $birthDate = $this->date($row['birth_date'] ?? '', 'birth_date');
        $hiredDate = $this->date($row['date_hired'] ?? '', 'date_hired');
        $regularizedDate = trim($row['date_regularized'] ?? '') !== ''
            ? $this->date($row['date_regularized'], 'date_regularized') : null;
        if ($regularizedDate !== null && $regularizedDate < $hiredDate) {
            throw new BusinessRuleException('date_regularized cannot be before date_hired.');
        }

        $status = strtolower(trim($row['status'] ?? ''));
        if ($status !== '') {
            $this->assertEnum($status, EmployeeStatus::values(), 'status');
            if ($status !== EmployeeStatus::Active->value) {
                throw new BusinessRuleException(
                    'Imported employees must start as active; use the separation workflow for former employees.',
                );
            }
        }

        $payload = [
            'employee_no'     => $employeeNo !== '' ? $employeeNo : null,
            'first_name'      => $first,
            'middle_name'     => trim($row['middle_name'] ?? '') ?: null,
            'last_name'       => $last,
            'suffix'          => trim($row['suffix'] ?? '') ?: null,
            'birth_date'      => $birthDate,
            'gender'          => $gender,
            'civil_status'    => $civil,
            // nationality is NOT NULL with a DB default of 'Filipino'. Passing
            // an explicit null overrides that default and hard-fails the insert,
            // which killed every employee CSV import that omitted the column.
            // Fall back to the same default rather than dropping the key, so the
            // value is explicit at the application layer too.
            'nationality'     => trim($row['nationality'] ?? '') ?: 'Filipino',
            'street_address'  => trim($row['street_address'] ?? '') ?: null,
            'city'            => trim($row['city'] ?? '') ?: null,
            'province'        => trim($row['province'] ?? '') ?: null,
            'mobile_number'   => $this->digits($row['mobile_number'] ?? '', 'mobile_number', 11, 11, '/^09\\d{9}$/'),
            'email'           => $this->email($row['email'] ?? ''),
            'sss_no'          => $this->digits($row['sss_no'] ?? '', 'sss_no', PhFormat::SSS_LEN, PhFormat::SSS_LEN),
            'philhealth_no'   => $this->digits($row['philhealth_no'] ?? '', 'philhealth_no', PhFormat::PHILHEALTH_LEN, PhFormat::PHILHEALTH_LEN),
            'pagibig_no'      => $this->digits($row['pagibig_no'] ?? '', 'pagibig_no', PhFormat::PAGIBIG_LEN, PhFormat::PAGIBIG_LEN),
            'tin'             => $this->digits($row['tin'] ?? '', 'tin', PhFormat::TIN_MIN, PhFormat::TIN_MAX),
            'department_id'   => $dept->id,
            'position_id'     => $position->id,
            'employment_type' => $empType,
            'pay_type'        => $payType,
            'date_hired'      => $hiredDate,
            'date_regularized' => $regularizedDate,
            'basic_monthly_salary' => $payType === PayType::Monthly->value ? $monthly : null,
            'semi_monthly_rate' => $payType === PayType::SemiMonthly->value ? $semiMonthly : null,
            'bank_name'       => trim($row['bank_name'] ?? '') ?: null,
            'bank_account_no' => $this->bankAccount($row['bank_account_no'] ?? ''),
        ];

        if ($status !== '') {
            $payload['status'] = $status;
        }

        $employee = $this->employees->create($payload);

        // EmployeeService owns the canonical hired-history row. Keep the one
        // bit of import provenance needed to remove a position that this CSV
        // created if the committed batch is later rolled back.
        if ($positionCreated) {
            $history = EmploymentHistory::query()
                ->where('employee_id', $employee->id)
                ->where('change_type', 'hired')
                ->latest('id')
                ->first();
            if ($history) {
                $to = (array) $history->to_value;
                $to['_import_created_position'] = true;
                $history->to_value = $to;
                $history->save();
            }
        }

        return $employee;
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

    /** @return array{0: Position, 1: bool} */
    private function resolvePosition(string $title, Department $dept): array
    {
        if ($title === '') {
            throw new RuntimeException('position is required.');
        }
        $position = Position::firstOrCreate(
            ['title' => $title, 'department_id' => $dept->id],
        );
        return [$position, (bool) $position->wasRecentlyCreated];
    }

    private function assertText(string $value, string $field): void
    {
        if (preg_match("/^[\\p{L}\\s.''\\-]+$/u", $value) !== 1) {
            throw new RuntimeException("Invalid {$field} value.");
        }
    }

    private function money(string $value, string $field): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\\d+(?:\\.\\d{1,2})?$/', $value) !== 1 || bccomp($value, '0', 2) < 0 || bccomp($value, '9999999.99', 2) > 0) {
            throw new RuntimeException("Invalid {$field} amount.");
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function digits(string $value, string $field, int $min, int $max, ?string $pattern = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $digits = PhFormat::digitsOnly($value);
        if (strlen($digits) < $min || strlen($digits) > $max || ($pattern !== null && preg_match($pattern, $digits) !== 1)) {
            throw new RuntimeException("Invalid {$field} value.");
        }
        return $digits;
    }

    private function email(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid email value.');
        }
        return strtolower($value);
    }

    private function bankAccount(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9\\-\\s]+$/', $value) !== 1) {
            throw new RuntimeException('Invalid bank_account_no value.');
        }
        return $value;
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
