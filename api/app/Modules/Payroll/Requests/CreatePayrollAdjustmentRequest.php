<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Enums\PayrollAdjustmentType;
use App\Modules\Payroll\Models\Payroll;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePayrollAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.adjustments.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'original_payroll_id' => ['required', 'string'],
            'type'                => ['required', Rule::in(PayrollAdjustmentType::values())],
            'amount'              => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'reason'              => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function validatedData(): array
    {
        $data = $this->validated();
        $payrollId = Payroll::tryDecodeHash((string) $data['original_payroll_id']);
        abort_if($payrollId === null, 422, 'Invalid payroll reference.');
        $data['original_payroll_id'] = $payrollId;
        return $data;
    }
}
