<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\CivilStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name'      => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name'       => ['sometimes', 'required', 'string', 'max:100'],
            'suffix'          => ['sometimes', 'nullable', 'string', 'max:20'],
            'birth_date'      => ['sometimes', 'date'],
            'gender'          => ['sometimes', Rule::in(Gender::values())],
            'civil_status'    => ['sometimes', Rule::in(CivilStatus::values())],
            'nationality'     => ['sometimes', 'nullable', 'string', 'max:50'],

            'street_address'  => ['sometimes', 'nullable', 'string', 'max:200'],
            'barangay'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'city'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'province'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'zip_code'        => ['sometimes', 'nullable', 'string', 'max:10'],

            'mobile_number'              => ['sometimes', 'nullable', 'string', 'max:20'],
            'email'                      => ['sometimes', 'nullable', 'email', 'max:255'],
            'emergency_contact_name'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'emergency_contact_relation' => ['sometimes', 'nullable', 'string', 'max:50'],
            'emergency_contact_phone'    => ['sometimes', 'nullable', 'string', 'max:20'],

            'sss_no'         => ['sometimes', 'nullable', 'string', 'max:30'],
            'philhealth_no'  => ['sometimes', 'nullable', 'string', 'max:30'],
            'pagibig_no'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'tin'            => ['sometimes', 'nullable', 'string', 'max:30'],

            'department_id'  => ['sometimes', 'string'],
            'position_id'    => ['sometimes', 'string'],

            'employment_type'      => ['sometimes', Rule::in(EmploymentType::values())],
            'pay_type'             => ['sometimes', Rule::in(PayType::values())],
            'date_hired'           => ['sometimes', 'date'],
            'date_regularized'     => ['sometimes', 'nullable', 'date'],
            'basic_monthly_salary' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'daily_rate'           => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],

            'bank_name'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_account_no' => ['sometimes', 'nullable', 'string', 'max:50'],

            'status'          => ['sometimes', Rule::in(EmployeeStatus::values())],
        ];
    }

    public function validatedData(): array
    {
        $data = $this->validated();
        if (array_key_exists('department_id', $data)) {
            $data['department_id'] = Department::tryDecodeHash($data['department_id']);
        }
        if (array_key_exists('position_id', $data)) {
            $data['position_id'] = Position::tryDecodeHash($data['position_id']);
        }
        return $data;
    }
}
