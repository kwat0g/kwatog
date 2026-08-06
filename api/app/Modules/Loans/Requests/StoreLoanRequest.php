<?php

declare(strict_types=1);

namespace App\Modules\Loans\Requests;

use App\Modules\HR\Models\Employee;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\SettingsService;
use App\Modules\Loans\Enums\LoanType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('loans.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('purpose'))) {
            $this->merge(['purpose' => trim((string) $this->input('purpose'))]);
        }
    }

    public function rules(): array
    {
        $maxPeriods = app(SettingsService::class)->requiredInt('loans.max_pay_periods', 1, 120);
        return [
            'employee_id' => ['required', 'string'],
            'loan_type'   => ['required', Rule::in(LoanType::values())],
            'principal'   => ['required', 'numeric', 'min:1'],
            'pay_periods' => ['required', 'integer', 'min:1', 'max:'.$maxPeriods],
            'purpose'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        $minimum = app(CurrencyDisplayService::class)->format(1);

        return [
            'principal.min'  => "Principal must be at least {$minimum}.",
            'pay_periods.max' => 'Pay periods exceed the configured maximum.',
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
