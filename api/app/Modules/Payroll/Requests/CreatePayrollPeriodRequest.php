<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.periods.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'period_start'        => ['required', 'date'],
            'period_end'          => ['required', 'date', 'after_or_equal:period_start'],
            'payroll_date'        => ['required', 'date', 'after_or_equal:period_end'],
            'is_first_half'       => ['required', 'boolean'],
            'is_thirteenth_month' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'period_end.after_or_equal' => 'Period end must be on or after the period start.',
            'payroll_date.after_or_equal' => 'Payroll date must be on or after the period end.',
        ];
    }
}
