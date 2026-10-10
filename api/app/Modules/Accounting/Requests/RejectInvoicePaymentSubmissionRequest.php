<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RejectInvoicePaymentSubmissionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('accounting.invoices.collect') ?? false; }
    public function rules(): array { return ['reason' => ['required', 'string', 'max:500']]; }
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) $this->merge(['reason' => trim($this->input('reason'))]);
    }
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['reason'])) $validator->errors()->add('reason', 'Only a rejection reason can be submitted.');
        }];
    }
}
