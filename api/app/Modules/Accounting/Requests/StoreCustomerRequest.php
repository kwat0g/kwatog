<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.customers.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:200'],
            'contact_person'    => ['nullable', 'string', 'max:100'],
            'email'             => ['nullable', 'email', 'max:200'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'address'           => ['nullable', 'string', 'max:500'],
            'tin'               => ['nullable', 'string', 'max:20'],
            'credit_limit'      => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'payment_terms_days'=> ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active'         => ['nullable', 'boolean'],
        ];
    }
}
