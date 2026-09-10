<?php

declare(strict_types=1);

namespace App\Modules\Loans\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordLoanPaymentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('loans.write_off') ?? false; }

    public function rules(): array
    {
        return [
            // Decimal-as-string: JS floats reintroduce the rounding error the
            // ledger's decimal(15,2) exists to prevent. Positivity and the
            // outstanding-balance cap are enforced under the row lock by
            // LoanService::recordPayment.
            'amount'       => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'remarks'      => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'Amount must be a positive decimal string with at most 2 decimal places.',
        ];
    }
}
