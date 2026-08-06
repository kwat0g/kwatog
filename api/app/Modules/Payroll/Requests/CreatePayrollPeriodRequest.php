<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\PayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Accepted for backward compatibility but IGNORED for normal
            // cutoffs: the service derives the half from period_start, because a
            // window that disagrees with its own label produced an inverted
            // cycle key and let the same employee be paid twice for one month.
            // See PayrollPeriod::deriveIsFirstHalf().
            'is_first_half'       => ['nullable', 'boolean'],
            'is_thirteenth_month' => ['nullable', 'boolean'],

            // ─── Scope filters (all optional) ────────────────────
            // Omitted / empty = company-wide, the historical behaviour. The
            // three filters AND together, so ["probationary"] + [dept] pays
            // probationary staff in that department only.
            'scope_employment_types'   => ['nullable', 'array', 'max:10'],
            'scope_employment_types.*' => ['string', Rule::in(EmploymentType::values())],

            'scope_pay_types'   => ['nullable', 'array', 'max:5'],
            'scope_pay_types.*' => ['string', Rule::in(PayType::values())],

            // Hash ids — decoded and existence-checked in the service so an
            // unknown department is a business error, not a leaked integer id.
            'scope_department_ids'   => ['nullable', 'array', 'max:50'],
            'scope_department_ids.*' => ['string'],

            'scope_label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'period_end.after_or_equal' => 'Period end must be on or after the period start.',
            'payroll_date.after_or_equal' => 'Payroll date must be on or after the period end.',
            'scope_employment_types.*.in' => 'One of the selected employment types is not recognised.',
            'scope_pay_types.*.in' => 'One of the selected pay types is not recognised.',
        ];
    }
}
