<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REC-01 — authorize + validate a payroll-period void.
 *
 * A void reverses the GL posting and is irreversible in intent, so a
 * substantive reason is mandatory for the audit trail (mirrors the service's
 * own guard at PayrollPeriodService::void()).
 */
class VoidPayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.periods.void') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
