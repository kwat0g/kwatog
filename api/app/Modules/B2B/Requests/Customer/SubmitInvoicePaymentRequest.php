<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use App\Modules\Accounting\Services\InvoicePaymentSubmissionService;
use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof CustomerPortalUser; }
    protected function prepareForValidation(): void
    {
        foreach (['sending_bank', 'reference_number'] as $field) {
            if (is_string($this->input($field))) $this->merge([$field => trim($this->input($field))]);
        }
    }
    public function rules(): array { return InvoicePaymentSubmissionService::submissionRules(); }
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) $validator->errors()->add('amount', 'Only payment details and evidence can be submitted.');
        }];
    }
}
