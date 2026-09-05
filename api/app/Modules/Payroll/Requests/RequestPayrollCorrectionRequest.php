<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A Finance checker must leave actionable evidence when returning a payroll to HR. */
class RequestPayrollCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.periods.request_correction') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
