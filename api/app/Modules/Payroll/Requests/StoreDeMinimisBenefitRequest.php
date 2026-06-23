<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\DeMinimisBenefitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeMinimisBenefitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.adjustments.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'  => ['required', 'string'],
            'benefit_type' => ['required', Rule::in(DeMinimisBenefitType::values())],
            'amount'       => ['required', 'numeric', 'min:0'],
            'period_year'  => ['required', 'integer', 'min:2020', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'notes'        => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Decode the hashed employee_id to an Employee model instance.
     */
    public function employee(): Employee
    {
        $id = Employee::tryDecodeHash((string) $this->validated('employee_id'));
        abort_if($id === null, 422, 'Invalid employee reference.');

        return Employee::findOrFail($id);
    }
}
