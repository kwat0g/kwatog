<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Common\Support\PhFormat;
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

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach ([
            'mobile_number', 'emergency_contact_phone',
            'sss_no', 'philhealth_no', 'pagibig_no', 'tin',
        ] as $field) {
            if ($this->has($field)) {
                $val = $this->input($field);
                $clean[$field] = is_string($val) ? PhFormat::digitsOnly($val) : $val;
            }
        }
        foreach ([
            'first_name', 'middle_name', 'last_name', 'suffix', 'nationality',
            'street_address', 'barangay', 'city', 'province', 'zip_code',
            'email', 'emergency_contact_name', 'emergency_contact_relation',
            'bank_name', 'bank_account_no',
        ] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $clean[$field] = trim((string) $this->input($field));
            }
        }
        if (!empty($clean)) {
            $this->merge($clean);
        }
    }

    public function rules(): array
    {
        return [
            'first_name'      => ['sometimes', 'required', 'string', 'max:100', "regex:/^[\\p{L}\\s.''\\-]+$/u"],
            'middle_name'     => ['sometimes', 'nullable', 'string', 'max:100', "regex:/^[\\p{L}\\s.''\\-]*$/u"],
            'last_name'       => ['sometimes', 'required', 'string', 'max:100', "regex:/^[\\p{L}\\s.''\\-]+$/u"],
            'suffix'          => ['sometimes', 'nullable', 'string', 'max:20'],
            'birth_date'      => ['sometimes', 'date', 'before:'.now()->subYears(15)->toDateString(), 'after:1900-01-01'],
            'gender'          => ['sometimes', Rule::in(Gender::values())],
            'civil_status'    => ['sometimes', Rule::in(CivilStatus::values())],
            'nationality'     => ['sometimes', 'nullable', 'string', 'max:50'],

            'street_address'  => ['sometimes', 'nullable', 'string', 'max:200'],
            'barangay'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'city'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'province'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'zip_code'        => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[0-9]{4,10}$/'],

            'mobile_number'              => ['sometimes', 'nullable', 'string', 'digits:11', 'regex:/^09\\d{9}$/'],
            'email'                      => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'emergency_contact_name'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'emergency_contact_relation' => ['sometimes', 'nullable', 'string', 'max:50'],
            'emergency_contact_phone'    => ['sometimes', 'nullable', 'string', 'digits_between:7,15'],

            'sss_no'         => ['sometimes', 'nullable', 'string', 'digits:'.PhFormat::SSS_LEN],
            'philhealth_no'  => ['sometimes', 'nullable', 'string', 'digits:'.PhFormat::PHILHEALTH_LEN],
            'pagibig_no'     => ['sometimes', 'nullable', 'string', 'digits:'.PhFormat::PAGIBIG_LEN],
            'tin'            => ['sometimes', 'nullable', 'string', 'digits_between:'.PhFormat::TIN_MIN.','.PhFormat::TIN_MAX],

            'department_id'  => ['sometimes', 'string'],
            'position_id'    => ['sometimes', 'string'],

            'employment_type'      => ['sometimes', Rule::in(EmploymentType::values())],
            'pay_type'             => ['sometimes', Rule::in(PayType::values())],
            'status'               => ['sometimes', Rule::in(EmployeeStatus::values())],
            'date_hired'           => ['sometimes', 'date', 'before_or_equal:today', 'after:1980-01-01'],
            'date_regularized'     => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'basic_monthly_salary' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'daily_rate'           => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999.99'],

            'bank_name'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_account_no' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\\-\\s]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.regex'  => 'Name may only contain letters, spaces, periods, apostrophes, or hyphens.',
            'last_name.regex'   => 'Name may only contain letters, spaces, periods, apostrophes, or hyphens.',
            'middle_name.regex' => 'Name may only contain letters, spaces, periods, apostrophes, or hyphens.',
            'mobile_number.digits' => 'Mobile number must be 11 digits.',
            'mobile_number.regex'  => 'Mobile number must start with 09 (e.g. 09171234567).',
            'sss_no.digits'        => 'SSS must be exactly 10 digits.',
            'philhealth_no.digits' => 'PhilHealth must be exactly 12 digits.',
            'pagibig_no.digits'    => 'Pag-IBIG must be exactly 12 digits.',
            'tin.digits_between'   => 'TIN must be 9 to 12 digits.',
            'zip_code.regex'       => 'ZIP code must be 4 to 10 digits.',
            'bank_account_no.regex'=> 'Account number may only contain letters, digits, spaces, or hyphens.',
            'birth_date.before'    => 'Employee must be at least 15 years old.',
            'date_hired.before_or_equal' => 'Hire date cannot be in the future.',
        ];
    }

    public function validatedData(): array
    {
        $data = $this->validated();
        if (array_key_exists('department_id', $data)) {
            $deptId = Department::tryDecodeHash($data['department_id']);
            abort_if($deptId === null, 422, 'Invalid department.');
            $data['department_id'] = $deptId;
        }
        if (array_key_exists('position_id', $data)) {
            $posId = Position::tryDecodeHash($data['position_id']);
            abort_if($posId === null, 422, 'Invalid position.');
            $data['position_id'] = $posId;
        }
        if (isset($data['department_id'], $data['position_id'])) {
            $position = Position::find($data['position_id']);
            abort_if(!$position || $position->department_id !== $data['department_id'], 422, 'Selected position does not belong to the chosen department.');
        }
        return $data;
    }
}
