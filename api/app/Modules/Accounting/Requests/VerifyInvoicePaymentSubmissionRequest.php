<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VerifyInvoicePaymentSubmissionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('accounting.invoices.collect') ?? false; }
    public function rules(): array { return ['cash_account_id' => ['required', 'string'], 'funds_received_confirmed' => ['required', 'accepted']]; }
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) $validator->errors()->add('cash_account_id', 'Submitted payment details cannot be changed during verification.');
        }];
    }
}
