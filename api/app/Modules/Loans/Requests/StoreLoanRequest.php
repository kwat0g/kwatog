<?php

declare(strict_types=1);

namespace App\Modules\Loans\Requests;

use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('loans.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string'],
            'loan_type'   => ['required', Rule::in(LoanType::values())],
            'principal'   => ['required', 'decimal:0,2', 'min:1'],
            'pay_periods' => ['required', 'integer', 'min:1', 'max:60'],
            'purpose'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $d['employee_id'] = Employee::tryDecodeHash($d['employee_id']);
        abort_if(!$d['employee_id'], 422, 'Invalid employee.');
        return $d;
    }
}
