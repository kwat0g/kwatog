<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideBillPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.bills.payment_approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
