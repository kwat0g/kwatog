<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REC-03 — request a salary change. Requires the maker permission; the actual
 * pay change is applied only after the salary_adjustment approval chain clears.
 */
class RequestSalaryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.salary_adjustments.request');
    }

    public function rules(): array
    {
        return [
            'to_basic_monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'required_without:to_semi_monthly_rate'],
            'to_semi_monthly_rate'           => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'required_without:to_basic_monthly_salary'],
            'effective_date'          => ['required', 'date'],
            'reason'                  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
