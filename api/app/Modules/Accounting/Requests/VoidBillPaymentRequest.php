<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidBillPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.bills.void_payment') ?? false;
    }

    public function rules(): array
    {
        return [
            'void_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'replacement_payment_id' => ['nullable', 'string'],
        ];
    }
}
