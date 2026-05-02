<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\CivilStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name'      => ['required', 'string', 'max:100'],
            'middle_name'     => ['nullable', 'string', 'max:100'],
            'last_name'       => ['required', 'string', 'max:100'],
            'suffix'          => ['nullable', 'string', 'max:20'],
            'birth_date'      => ['required', 'date', 'before:'.now()->subYears(15)->toDateString()],
            'gender'          => ['required', Rule::in(Gender::values())],
            'civil_status'    => ['required', Rule::in(CivilStatus::values())],
            'nationality'     => ['nullable', 'string', 'max:50'],

            'street_address'  => ['nullable', 'string', 'max:200'],
            'barangay'        => ['nullable', 'string', 'max:100'],
            'city'            => ['nullable', 'string', 'max:100'],
            'province'        => ['nullable', 'string', 'max:100'],
            'zip_code'        => ['nullable', 'string', 'max:10'],

            'mobile_number'                => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'email'                        => ['nullable', 'email', 'max:255'],
            'emergency_contact_name'       => ['nullable', 'string', 'max:100'],
            'emergency_contact_relation'   => ['nullable', 'string', 'max:50'],
            'emergency_contact_phone'      => ['nullable', 'string', 'max:20'],

            'sss_no'         => ['nullable', 'string', 'max:30'],
            'philhealth_no'  => ['nullable', 'string', 'max:30'],
            'pagibig_no'     => ['nullable', 'string', 'max:30'],
            'tin'            => ['nullable', 'string', 'max:30'],

            'department_id'  => ['required', 'string'],
            'position_id'    => ['required', 'string'],

            'employment_type'      => ['required', Rule::in(EmploymentType::values())],
            'pay_type'             => ['required', Rule::in(PayType::values())],
            'date_hired'           => ['required', 'date', 'before_or_equal:today'],
            'date_regularized'     => ['nullable', 'date', 'after_or_equal:date_hired'],
            'basic_monthly_salary' => ['required_if:pay_type,monthly', 'nullable', 'decimal:0,2', 'min:0'],
            'daily_rate'           => ['required_if:pay_type,daily', 'nullable', 'decimal:0,2', 'min:0'],

            'bank_name'       => ['nullable', 'string', 'max:100'],
            'bank_account_no' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before' => 'Employee must be at least 15 years old.',
            'date_hired.before_or_equal' => 'Hire date cannot be in the future.',
            'basic_monthly_salary.required_if' => 'Monthly salary is required for monthly-paid employees.',
            'daily_rate.required_if' => 'Daily rate is required for daily-paid employees.',
            'mobile_number.regex' => 'Mobile number contains invalid characters.',
        ];
    }

    public function validatedData(): array
    {
        $data = $this->validated();
        $deptId = Department::tryDecodeHash($data['department_id']);
        $posId  = Position::tryDecodeHash($data['position_id']);
        abort_if($deptId === null, 422, 'Invalid department.');
        abort_if($posId === null, 422, 'Invalid position.');
        $data['department_id'] = $deptId;
        $data['position_id'] = $posId;

        // Cross-rule: position must belong to department.
        $position = Position::find($posId);
        abort_if(!$position || $position->department_id !== $deptId, 422, 'Selected position does not belong to the chosen department.');

        return $data;
    }
}
